<?php
/**
 * ERA File Viewer popup
 * Matches EDI files by scanning TRN*1*{checkNum} segments.
 * File naming: {YYYYMMDD}_{ISA_control}_{payerID}.edi
 * ar_session.reference = "ePay - 790427237" → check_num = "790427237" = TRN value
 */
require_once("../globals.php");
use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Twig\TwigContainer;
use OpenEMR\Core\Header;

if (!AclMain::aclCheckCore('acct', 'bill', '', 'write') && !AclMain::aclCheckCore('acct', 'eob', '', 'write')) {
    echo (new TwigContainer(null, $GLOBALS['kernel']))->getTwig()->render('core/unauthorized.html.twig', ['pageTitle' => xl("ERA Viewer")]);
    exit;
}

$ref        = isset($_GET['ref'])        ? trim($_GET['ref'])        : '';
$session_id = isset($_GET['session_id']) ? intval($_GET['session_id']) : 0;
$force_rebuild = !empty($_GET['rebuild']);

// Session details for header
$session_row = [];
if ($session_id > 0) {
    $rs = sqlQuery("SELECT s.*, ic.name AS ins_name FROM ar_session s LEFT JOIN insurance_companies ic ON ic.id = s.payer_id WHERE s.session_id = ?", [$session_id]);
    if ($rs) $session_row = $rs;
}

// Extract check number from reference e.g. "ePay - 790427237" → "790427237"
$check_num = '';
if (preg_match('/(\d+)\s*$/', $ref, $m)) {
    $check_num = $m[1];
}

// ── Resolve ERA directory with multiple fallbacks ────────────────────────────
// OE_SITE_DIR is relative on some installs (e.g. CloudClusters).
// The correct absolute path is typically: {oe_root}/sites/default/documents/era/
// This resolver tries every known strategy and picks the first readable directory.
function resolve_era_dir() {
    $candidates = [];

    // 0. CloudClusters hardcoded: FTP root is /, openemr lives at /openemr
    $candidates[] = '/openemr/sites/default/documents/era/';

    // 1. OE_SITE_DIR global — use directly if absolute
    if (!empty($GLOBALS['OE_SITE_DIR'])) {
        $d = rtrim($GLOBALS['OE_SITE_DIR'], '/');
        $candidates[] = $d . '/documents/era/';
    }

    // 2. Walk up from __FILE__ trying each ancestor for sites/default/documents/era
    //    __FILE__ = /cloudclusters/openemr/interface/billing/era_viewer.php
    //    Try: 2, 3, 4, 5 levels up
    $dir = __DIR__;
    for ($i = 0; $i < 6; $i++) {
        $dir = dirname($dir);
        $candidates[] = $dir . '/sites/default/documents/era/';
        // Also try if OE_SITE_DIR is a subpath of this ancestor
        if (!empty($GLOBALS['OE_SITE_DIR'])) {
            $sitename = basename($GLOBALS['OE_SITE_DIR']); // e.g. "default"
            $candidates[] = $dir . '/sites/' . $sitename . '/documents/era/';
        }
    }

    // 3. DOCUMENT_ROOT strategies
    $docroot = rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/');
    if ($docroot) {
        $candidates[] = $docroot . '/sites/default/documents/era/';
        $candidates[] = $docroot . '/openemr/sites/default/documents/era/';
        // Walk up from docroot too
        $d = $docroot;
        for ($i = 0; $i < 3; $i++) {
            $d = dirname($d);
            $candidates[] = $d . '/sites/default/documents/era/';
        }
    }

    // 4. srcdir global — strip known suffixes to find OpenEMR root
    foreach (['srcdir','webroot_path','rootdir'] as $k) {
        if (empty($GLOBALS[$k])) continue;
        $base = rtrim($GLOBALS[$k], '/');
        foreach (['/library','/src','/interface','/interface/billing'] as $s) {
            if (substr($base, -strlen($s)) === $s) $base = substr($base, 0, -strlen($s));
        }
        $candidates[] = $base . '/sites/default/documents/era/';
    }

    // 5. OE_SITE_DIR constant
    if (defined('OE_SITE_DIR')) {
        $candidates[] = rtrim(OE_SITE_DIR, '/') . '/documents/era/';
    }

    // Deduplicate and test
    // NOTE: realpath() returns false on CloudClusters even for valid paths due to
    // container filesystem mapping. We test is_dir/is_readable on the raw path
    // and only use realpath() for display purposes.
    $seen = [];
    $GLOBALS['_era_candidates_tried'] = [];
    foreach ($candidates as $c) {
        $c = str_replace('//', '/', $c);
        if (isset($seen[$c])) continue;
        $seen[$c] = true;
        $real    = realpath($c); // may be false — that's OK
        $exists  = is_dir($c);   // test raw path, not realpath result
        $readable = ($exists && is_readable($c));
        $GLOBALS['_era_candidates_tried'][] = [
            'path'     => $c,
            'realpath' => $real ?: '(no realpath)',
            'exists'   => $exists,
            'readable' => $readable,
        ];
        if ($readable) {
            // Return realpath if available, otherwise the raw path
            return rtrim($real ?: $c, '/') . '/';
        }
    }

    // Nothing worked — return the OE_SITE_DIR-based path for the error display
    $best = !empty($GLOBALS['OE_SITE_DIR'])
        ? rtrim($GLOBALS['OE_SITE_DIR'], '/') . '/documents/era/'
        : '/sites/default/documents/era/';
    return $best;
}

$era_dir  = resolve_era_dir();
$idx_path = $era_dir . '.era_index.json';

// ── Scan all EDI files, build TRN→basename map ───────────────────────────────
// Returns: ['790427237'=>'20260401_47004338_710', ...]
// Uses a JSON cache; ?rebuild=1 forces a fresh scan.
function era_build_index($era_dir, $idx_path, $force) {
    $index = [];
    // Load cache unless forced
    if (!$force && is_readable($idx_path)) {
        $raw = @file_get_contents($idx_path);
        if ($raw) {
            $dec = json_decode($raw, true);
            if (is_array($dec) && count($dec) > 0) {
                $index = $dec;
            }
        }
    }

    $dirty    = false;
    $edi_exts = ['edi','835','x12'];

    // Build a reverse map: basename => [trn, trn, ...] from current index
    // so we can detect files that have been partially indexed.
    $indexed_by_file = [];
    foreach ($index as $trn => $base) {
        $indexed_by_file[$base][] = $trn;
    }

    foreach (scandir($era_dir) as $fn) {
        if ($fn[0] === '.') continue;
        $ext = strtolower(pathinfo($fn, PATHINFO_EXTENSION));
        if (!in_array($ext, $edi_exts)) continue;
        $base = pathinfo($fn, PATHINFO_FILENAME);
        $path = $era_dir . $fn;
        if (!is_readable($path)) continue;

        // Read file and extract ALL TRN check numbers
        $content = @file_get_contents($path);
        if ($content === false) continue;
        if (!preg_match_all('/TRN\*1\*(\d+)/i', $content, $mall)) continue;

        $file_trns = array_unique($mall[1]);

        // Skip only if every TRN in this file is already in the index pointing
        // to this file. A file is re-processed if it has new TRNs not yet indexed
        // (handles multi-check 835 files where earlier indexing only caught one TRN).
        if (!$force && isset($indexed_by_file[$base])) {
            $already = $indexed_by_file[$base];
            $missing = array_diff($file_trns, $already);
            if (empty($missing)) continue; // fully indexed, skip
        }

        foreach ($file_trns as $trn) {
            if (!isset($index[$trn])) {
                $index[$trn] = $base;
                $dirty = true;
            }
        }
    }
    if ($dirty || ($force && count($index) > 0)) {
        @file_put_contents($idx_path, json_encode($index, JSON_PRETTY_PRINT));
    }
    return $index;
}

// ── Debug info (collected regardless, shown only on "not found") ─────────────
$debug = [];
$debug['era_dir']       = $era_dir;
$debug['oe_site_dir_raw'] = $GLOBALS['OE_SITE_DIR'] ?? '(not set)';
$debug['doc_root']        = $_SERVER['DOCUMENT_ROOT'] ?? '(not set)';
$debug['this_file']       = __FILE__;
$debug['dir_exists']    = is_dir($era_dir);
$debug['dir_readable']  = is_readable($era_dir);
$debug['dir_writable']  = is_writable($era_dir);
$debug['idx_exists']    = file_exists($idx_path);
$debug['check_num']     = $check_num;
$debug['edi_files']     = [];

$edi_file     = null;
$edi_filename = '';
$html_files   = [];
$edi_base     = null;

if ($check_num && $debug['dir_exists']) {
    $index = era_build_index($era_dir, $idx_path, $force_rebuild);
    $debug['index_count'] = count($index);
    $debug['index_has_key'] = isset($index[$check_num]);

    // Collect debug info on all EDI files
    foreach (scandir($era_dir) as $fn) {
        if ($fn[0] === '.') continue;
        $ext = strtolower(pathinfo($fn, PATHINFO_EXTENSION));
        if (!in_array($ext, ['edi','835','x12'])) continue;
        $path = $era_dir . $fn;
        $readable = is_readable($path);
        $trns = [];
        if ($readable) {
            $content2 = @file_get_contents($path);
            if ($content2 !== false && preg_match_all('/TRN\*1\*(\d+)/i', $content2, $m2)) {
                $trns = array_unique($m2[1]);
            }
        }
        // trn field: all matched check numbers as comma-separated string (or null)
        $debug['edi_files'][] = [
            'file'     => $fn,
            'trn'      => !empty($trns) ? implode(', ', $trns) : null,
            'trns'     => $trns,
            'readable' => $readable,
        ];
    }

    // Primary: index lookup
    if (isset($index[$check_num])) {
        $edi_base = $index[$check_num];
    }
    // Fallback: filename scan
    if (!$edi_base) {
        foreach (scandir($era_dir) as $fn) {
            if ($fn[0] === '.') continue;
            $ext = strtolower(pathinfo($fn, PATHINFO_EXTENSION));
            if (!in_array($ext, ['edi','835','x12'])) continue;
            if (strpos($fn, $check_num) !== false) {
                $edi_base = pathinfo($fn, PATHINFO_FILENAME);
                break;
            }
        }
    }

    if ($edi_base) {
        foreach (['edi','835','x12'] as $ext) {
            $p = $era_dir . $edi_base . '.' . $ext;
            if (is_readable($p)) {
                $edi_file     = @file_get_contents($p);
                $edi_filename = $edi_base . '.' . $ext;
                break;
            }
        }
        // No longer look for or use .html files from the server — the Summary tab
        // is built directly from the EDI content below to avoid "Access Denied".
        $html_files = []; // always empty; kept for template compatibility
    }
}

$has_edi        = ($edi_file !== null);
$has_html       = false; // html files no longer used; summary built from EDI
$found_anything = $has_edi || $has_html;

/**
 * Parse an 835 EDI string into a structured summary array.
 */
function era_parse_835($raw) {
    if (!preg_match('/ISA(.)/s', $raw, $sm)) return null;
    $elem_sep = $sm[1];
    $seg_term = substr($raw, 105, 1);
    if ($seg_term === '') $seg_term = '~';
    $raw_segs = preg_split('/[' . preg_quote($seg_term, '/') . ']\s*/s', $raw);
    $segments = [];
    foreach ($raw_segs as $s) {
        $s = trim($s);
        if ($s === '') continue;
        $segments[] = explode($elem_sep, $s);
    }
    $result = [
        'payer'  => '', 'payee'  => '',
        'checks' => [],
        'totals' => ['paid'=>0.0,'claims'=>0,'adjustments'=>0.0,'plb'=>0.0],
    ];
    $current_check = null;
    $current_claim = null;
    $current_svc   = null;
    foreach ($segments as $seg) {
        $id = strtoupper($seg[0] ?? '');
        if ($id === 'BPR' && isset($seg[2])) {
            $result['totals']['paid'] += (float)$seg[2];
        }
        if ($id === 'TRN') {
            if ($current_check !== null) {
                if ($current_svc !== null && $current_claim !== null) { $current_claim['svcs'][] = $current_svc; $current_svc = null; }
                if ($current_claim !== null) { $current_check['claims'][] = $current_claim; $current_claim = null; }
                $result['checks'][] = $current_check;
            }
            $current_check = ['trn'=>$seg[2]??'','origin'=>$seg[3]??'','claims'=>[]];
        }
        if ($id === 'NM1') {
            $loop  = $seg[1] ?? '';
            $last  = $seg[3] ?? '';
            $first = $seg[4] ?? '';
            // "Last, First" for people; plain org name for entities
            $name  = $first ? trim($last . ', ' . $first) : trim($last);
            if ($loop === 'PR') $result['payer'] = $name;
            if ($loop === 'PE') $result['payee'] = $name;
            // QC = Patient, IL = Insured/Subscriber — attach to whichever claim is open
            if ($loop === 'QC' && $current_claim !== null) $current_claim['patient_name']    = $name;
            if ($loop === 'IL' && $current_claim !== null) $current_claim['subscriber_name'] = $name;
        }
        if ($id === 'CLP' && $current_check !== null) {
            if ($current_svc !== null && $current_claim !== null) { $current_claim['svcs'][] = $current_svc; $current_svc = null; }
            if ($current_claim !== null) { $current_check['claims'][] = $current_claim; }
            $result['totals']['claims']++;
            $current_claim = [
                'clp_id'=>$seg[1]??'', 'status'=>$seg[2]??'',
                'billed'=>(float)($seg[3]??0), 'paid'=>(float)($seg[4]??0),
                'patient_resp'=>(float)($seg[5]??0),
                'payer_claim_id'=>$seg[7]??'',
                'patient_name'=>'', 'subscriber_name'=>'',
                'dos'=>'',
                'svcs'=>[], 'cas'=>[],
            ];
            $current_svc = null;
        }
        if ($id === 'SVC' && $current_claim !== null) {
            if ($current_svc !== null) $current_claim['svcs'][] = $current_svc;
            $pp = explode(':', $seg[1]??'');
            $current_svc = [
                'qualifier'=>$pp[0]??'', 'code'=>$pp[1]??($seg[1]??''),
                'mod1'=>$pp[2]??'', 'mod2'=>$pp[3]??'',
                'billed'=>(float)($seg[2]??0), 'paid'=>(float)($seg[3]??0),
                'units'=>(int)($seg[5]??1), 'cas'=>[], 'dos'=>'',
            ];
        }
        if ($id === 'CAS') {
            $group = $seg[1]??'';
            $cas_entry = ['group'=>$group,'adjustments'=>[]];
            for ($ci=2; $ci+1<count($seg); $ci+=3) {
                $reason=$seg[$ci]??''; $amount=(float)($seg[$ci+1]??0);
                if ($reason===''&&$amount==0) break;
                $cas_entry['adjustments'][]=['reason'=>$reason,'amount'=>$amount];
                // Exclude PR (Patient Responsibility) — not an insurance adjustment
                if ($group !== 'PR') $result['totals']['adjustments']+=$amount;
            }
            if ($current_svc!==null) $current_svc['cas'][]=$cas_entry;
            elseif ($current_claim!==null) $current_claim['cas'][]=$cas_entry;
        }
        if ($id === 'DTM') {
            $qualifier = $seg[1] ?? '';
            $raw_date  = $seg[2] ?? '';
            // Normalize YYYYMMDD -> YYYY-MM-DD
            $fmt_date  = (strlen($raw_date) === 8)
                ? substr($raw_date,0,4).'-'.substr($raw_date,4,2).'-'.substr($raw_date,6,2)
                : $raw_date;
            if ($qualifier === '472' && $current_svc !== null) {
                // DTM*472 = service date for this specific service line
                $current_svc['dos'] = $fmt_date;
            } elseif ($qualifier === '232' && $current_claim !== null) {
                // DTM*232 = statement from date (claim-level DOS)
                if (empty($current_claim['dos'])) $current_claim['dos'] = $fmt_date;
            } elseif ($qualifier === '233' && $current_claim !== null) {
                // DTM*233 = statement to date — only use as fallback
                if (empty($current_claim['dos'])) $current_claim['dos'] = $fmt_date;
            }
        }
        if ($id === 'PLB') {
            for ($pi=3;$pi+1<count($seg);$pi+=2) $result['totals']['plb']+=(float)($seg[$pi+1]??0);
        }
        if ($id === 'SE') {
            if ($current_svc!==null&&$current_claim!==null){$current_claim['svcs'][]=$current_svc;$current_svc=null;}
            if ($current_claim!==null&&$current_check!==null){$current_check['claims'][]=$current_claim;$current_claim=null;}
            if ($current_check!==null){$result['checks'][]=$current_check;$current_check=null;}
        }
    }
    if ($current_svc!==null&&$current_claim!==null) $current_claim['svcs'][]=$current_svc;
    if ($current_claim!==null&&$current_check!==null) $current_check['claims'][]=$current_claim;
    if ($current_check!==null) $result['checks'][]=$current_check;
    return $result;
}
function era_clp_status($code) {
    $map=['1'=>'Processed as Primary','2'=>'Processed as Secondary','3'=>'Processed as Tertiary',
          '4'=>'Denied','19'=>'Fwd to Additional Payer (Primary)','20'=>'Fwd to Additional Payer (Secondary)',
          '22'=>'Reversal of Previous Payment','23'=>'Not Our Claim','25'=>'Predetermination Only'];
    return $map[$code]??'Status '.$code;
}
function era_cas_group($g) {
    $map=['CO'=>'Contractual','OA'=>'Other Adjustment','PI'=>'Payer Initiated','PR'=>'Patient Responsibility'];
    return ($map[$g]??$g).': ';
}
$era_summary = ($has_edi && $edi_file) ? era_parse_835($edi_file) : null;

// If the file contains multiple checks (multi-TRN 835), filter to only the
// one matching the requested check number so the summary shows the right ERA.
if ($era_summary && $check_num && count($era_summary['checks']) > 1) {
    $matched_checks = array_filter($era_summary['checks'], function($ck) use ($check_num) {
        return (string)$ck['trn'] === (string)$check_num;
    });
    if (!empty($matched_checks)) {
        // Recalculate totals for just this check
        $era_summary['checks'] = array_values($matched_checks);
        $era_summary['totals']['paid']        = 0.0;
        $era_summary['totals']['claims']      = 0;
        $era_summary['totals']['adjustments'] = 0.0;
        foreach ($era_summary['checks'] as $ck) {
            foreach ($ck['claims'] as $cl) {
                $era_summary['totals']['paid']   += $cl['paid'];
                $era_summary['totals']['claims'] += 1;
                foreach ($cl['svcs'] as $sv) {
                    foreach ($sv['cas'] as $ca) {
                        if ($ca['group'] === 'PR') continue;
                        foreach ($ca['adjustments'] as $adj) {
                            $era_summary['totals']['adjustments'] += $adj['amount'];
                        }
                    }
                }
            }
        }
    }
}

// ── Fetch posted data from OpenEMR for this session ──────────────────────────
// Fetch per-encounter so we can match each ERA claim to its posted lines.
// ar_activity.encounter is the OpenEMR encounter number; ERA CLP01 (clp_id) is
// typically the original claim/encounter ref sent on the 837 — we match on that.
// Key structure: $posted_by_enc[encounter]["CPT-MOD"] = ['paid'=>x,'adj'=>x]
$posted_by_enc     = [];
$posted_total_paid = 0.0;
$posted_total_adj  = 0.0;

if ($session_id > 0) {
    // NOTE: 'deleted' and 'payer_claim_number' omitted — not present in all OpenEMR versions.
    $sql = "
    SELECT
    aa.pid,
    p.fname,
    p.lname,

    COALESCE(NULLIF(aa.payer_claim_number, ''), aa.encounter) AS claim_no,

    aa.encounter,
    aa.post_date AS dos,

    aa.code AS cpt,
    COALESCE(aa.modifier, '') AS modifier,

    SUM(aa.pay_amount) AS posted_paid,
    SUM(aa.adj_amount) AS posted_adj

FROM ar_activity aa

LEFT JOIN patient_data p
    ON p.pid = aa.pid

WHERE aa.session_id = ?
AND aa.deleted IS NULL
AND aa.code IS NOT NULL
AND aa.code != ''

GROUP BY
    aa.pid,
    p.fname,
    p.lname,
    claim_no,
    aa.encounter,
    aa.post_date,
    aa.code,
    aa.modifier

ORDER BY
    p.lname,
    p.fname,
    aa.encounter,
    aa.code,
    aa.modifier;
";


    $res = sqlStatement($sql, [$session_id]);
    while ($row = sqlFetchArray($res)) {

      $claim_key = (string)$row['claim_match'];
  
      $svc_key = $row['code'] .
          (!empty($row['modifier']) ? '-' . $row['modifier'] : '');
  
      if (!isset($posted_by_enc[$claim_key])) {
          $posted_by_enc[$claim_key] = [];
      }
  
      if (!isset($posted_by_enc[$claim_key][$svc_key])) {
          $posted_by_enc[$claim_key][$svc_key] = [
              'cpt'  => $row['code'],
              'mod'  => $row['modifier'],
              'paid' => 0.0,
              'adj'  => 0.0
          ];
      }
  
      $posted_by_enc[$claim_key][$svc_key]['paid'] += (float)$row['paid'];
      $posted_by_enc[$claim_key][$svc_key]['adj']  += (float)$row['adj'];
  
      $posted_total_paid += (float)$row['paid'];
      $posted_total_adj  += (float)$row['adj'];
  }
}

// Also build a flat session-wide posted map for the totals strip
$posted_lines = [];
foreach ($posted_by_enc as $enc_lines) {
    foreach ($enc_lines as $ckey => $line) {
        if (!isset($posted_lines[$ckey])) $posted_lines[$ckey] = ['paid'=>0.0,'adj'=>0.0];
        $posted_lines[$ckey]['paid'] += $line['paid'];
        $posted_lines[$ckey]['adj']  += $line['adj'];
    }
}

// Helper: given an ERA claim's clp_id (OpenEMR encounter ref), find the best
// matching encounter in posted_by_enc.
// The CLP01 sent on 837s is typically the encounter number itself, or may be
// prefixed/suffixed. We try: exact match, numeric suffix, then fall back to
// the aggregate session-wide map.
function find_posted_for_claim($clp_id, $posted_by_enc, $posted_lines) {
    $clp_id = trim($clp_id);
    // 1. Direct encounter match
    if (isset($posted_by_enc[$clp_id])) return $posted_by_enc[$clp_id];
    // 2. Numeric-only tail of clp_id (e.g. "PAT-123" -> "123")
    if (preg_match('/(\d+)$/', $clp_id, $m) && isset($posted_by_enc[$m[1]])) {
        return $posted_by_enc[$m[1]];
    }
    // 3. clp_id is a prefix of an encounter key
    foreach ($posted_by_enc as $enc => $lines) {
        if (strpos((string)$enc, $clp_id) === 0 || strpos($clp_id, (string)$enc) === 0) {
            return $lines;
        }
    }
    // 4. Single encounter in session — unambiguous, use it
    
    // 5. No match — return empty (will show as MISSING)
    return [];
}

// ── Build ERA service-line map keyed by CPT for reconciliation ───────────────
$era_svc_map = [];   // "CPT[-MOD]" => ['cpt','mod','paid','adj','billed']
if ($era_summary) {
    foreach ($era_summary['checks'] as $ck) {
        foreach ($ck['claims'] as $cl) {
            foreach ($cl['svcs'] as $sv) {
                $key = $sv['code'] . ($sv['mod1'] ? '-'.$sv['mod1'] : '');
                if (!isset($era_svc_map[$key])) {
                    $era_svc_map[$key] = ['cpt'=>$sv['code'],'mod'=>$sv['mod1'],'paid'=>0.0,'adj'=>0.0,'billed'=>0.0];
                }
                $era_svc_map[$key]['paid']   += $sv['paid'];
                $era_svc_map[$key]['billed'] += $sv['billed'];
                foreach ($sv['cas'] as $ca) {
                    if ($ca['group'] === 'PR') continue; // patient resp, not ins adj
                    foreach ($ca['adjustments'] as $adj) {
                        $era_svc_map[$key]['adj'] += $adj['amount'];
                    }
                }
            }
        }
    }
}

// Reconciliation status helper
function recon_status($era_paid, $posted_paid) {
    $era_paid    = round((float)$era_paid,   2);
    $posted_paid = round((float)$posted_paid, 2);
    if ($era_paid == 0 && $posted_paid == 0) return ['label'=>'','icon'=>'','cls'=>''];
    $diff = round($posted_paid - $era_paid, 2);
    $pct  = $era_paid != 0 ? abs($diff / $era_paid) : 1;
    if ($posted_paid == 0 && $era_paid  > 0) return ['label'=>'MISSING',    'icon'=>'❌','cls'=>'recon-missing'];
    if ($era_paid  == 0 && $posted_paid > 0) return ['label'=>'OVERPOSTED', 'icon'=>'🚨','cls'=>'recon-over'];
    if (abs($diff) < 0.01)                   return ['label'=>'MATCHED',    'icon'=>'✅','cls'=>'recon-match'];
    if ($diff > 0.01)                        return ['label'=>'OVERPOSTED', 'icon'=>'🚨','cls'=>'recon-over'];
    if ($pct <= 0.10 || abs($diff) < 1.00)  return ['label'=>'PARTIAL',    'icon'=>'⚠️','cls'=>'recon-partial'];
    return                                          ['label'=>'PARTIAL',    'icon'=>'⚠️','cls'=>'recon-partial'];
}

$era_total  = $era_summary ? $era_summary['totals']['paid'] : 0.0;
$recon_diff = round($posted_total_paid - $era_total, 2);
$check_date = (!empty($session_row['check_date']) && $session_row['check_date'] !== '0000-00-00')
              ? $session_row['check_date'] : '';

?><!DOCTYPE html>
<html>
<head>
<title><?php echo xlt('ERA Viewer'); ?></title>
<?php Header::setupHeader(); ?>
<style>
:root{--brand:#0369a1;--border:#e5e7eb;--subtle:#f8fafc;--text:#111827;--muted:#6b7280;--mono:'JetBrains Mono','Fira Mono','Courier New',monospace;--sans:'Inter',system-ui,sans-serif;}
*,*::before,*::after{box-sizing:border-box;}
html,body{margin:0;padding:0;background:var(--subtle);font-family:var(--sans);font-size:13px;color:var(--text);-webkit-font-smoothing:antialiased;}
.era-header{position:sticky;top:0;z-index:100;background:linear-gradient(135deg,#0c4a6e 0%,#0369a1 100%);color:#fff;padding:11px 18px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;}
.era-header h1{margin:0;font-size:14px;font-weight:700;display:flex;align-items:center;gap:7px;}
.era-meta{display:flex;flex-wrap:wrap;gap:14px;font-size:11px;color:#bae6fd;}
.era-meta strong{color:#fff;}
.era-tabs{position:sticky;top:42px;z-index:99;background:#fff;border-bottom:2px solid var(--border);display:flex;align-items:flex-end;padding:0 18px;overflow-x:auto;}
.era-tab{padding:9px 16px 8px;font-size:11.5px;font-weight:600;cursor:pointer;border:none;background:none;color:var(--muted);border-bottom:3px solid transparent;margin-bottom:-2px;white-space:nowrap;transition:color .12s;}
.era-tab:hover{color:var(--brand);}
.era-tab.active{color:var(--brand);border-bottom-color:var(--brand);}
.era-tab-badge{display:inline-block;background:#e0f2fe;color:#0369a1;border-radius:10px;font-size:10px;padding:1px 6px;margin-left:5px;}
.era-toolbar{position:sticky;top:80px;z-index:98;background:#fff;border-bottom:1px solid var(--border);padding:7px 18px;display:flex;align-items:center;gap:10px;flex-wrap:wrap;}
.era-toolbar label{font-size:10.5px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:.05em;margin:0;}
.era-toolbar input[type=text]{border:1.5px solid var(--border);border-radius:5px;padding:5px 10px;font-size:12px;width:200px;outline:none;}
.era-toolbar input[type=text]:focus{border-color:var(--brand);}
.era-toolbar .btn-hl{background:var(--brand);color:#fff;border:none;border-radius:5px;padding:5px 13px;font-size:11.5px;font-weight:600;cursor:pointer;}
.era-toolbar .btn-hl:hover{background:#075985;}
.match-count{font-size:11px;color:var(--muted);white-space:nowrap;}
.era-filename{font-size:10.5px;color:var(--muted);font-family:var(--mono);margin-left:auto;}
.era-pane{display:none;}
.era-pane.active{display:block;}
pre#era-content{margin:14px 18px;background:#fff;border:1px solid var(--border);border-radius:10px;padding:16px 18px;font-family:var(--mono);font-size:11.5px;line-height:1.75;white-space:pre-wrap;word-break:break-all;color:var(--text);overflow-y:visible;box-shadow:inset 0 1px 3px rgba(0,0,0,.04);}
.era-iframe-wrap{margin:14px 18px;border:1px solid var(--border);border-radius:10px;overflow:hidden;}
.era-iframe-wrap iframe{width:100%;height:calc(100vh - 230px);border:none;background:#fff;}
/* Not found / debug */
.era-not-found{margin:20px 18px;background:#fff7ed;border:1px solid #fed7aa;border-radius:10px;padding:20px 24px;color:#9a3412;}
.era-not-found h2{font-size:15px;margin:0 0 10px;}
.debug-table{width:100%;border-collapse:collapse;font-size:11.5px;margin-top:12px;}
.debug-table th{background:#fef3c7;text-align:left;padding:5px 10px;font-weight:700;font-size:10.5px;text-transform:uppercase;letter-spacing:.05em;}
.debug-table td{padding:5px 10px;border-bottom:1px solid #fed7aa;font-family:var(--mono);}
.debug-table tr:last-child td{border-bottom:none;}
.ok{color:#16a34a;font-weight:700;}
.fail{color:#dc2626;font-weight:700;}
.btn-rebuild{display:inline-block;margin-top:14px;background:var(--brand);color:#fff;border:none;border-radius:6px;padding:7px 16px;font-size:12px;font-weight:600;cursor:pointer;text-decoration:none;}
/* 835 colours */
mark.era-hl{background:#fef08a;color:#713f12;border-radius:2px;padding:0 1px;}
mark.era-hl-cur{background:#fb923c;color:#fff;border-radius:2px;padding:0 1px;}
.seg-ISA,.seg-GS{color:#1d4ed8;font-weight:600;}
.seg-ST{color:#7c3aed;font-weight:600;}
.seg-CLP{color:#b45309;font-weight:700;}
.seg-SVC{color:#0f766e;}
.seg-CAS{color:#9d174d;}
.seg-NM1{color:#1e40af;}
.seg-TRN{color:#0369a1;font-weight:600;}
.seg-BPR{color:#0891b2;font-weight:600;}
.seg-SE,.seg-GE,.seg-IEA{color:#9ca3af;}
/* ── Summary layout ─────────────────────────────── */
.era-sum-topbar{position:sticky;top:80px;z-index:98;background:#fff;border-bottom:1px solid var(--border);padding:10px 20px;display:flex;align-items:center;justify-content:space-between;gap:10px;}
.btn-sum-action{display:inline-flex;align-items:center;gap:5px;border:1.5px solid var(--border);border-radius:6px;padding:5px 13px;font-size:11.5px;font-weight:600;cursor:pointer;background:#fff;color:var(--text);transition:all .12s;}
.btn-download{border-color:#16a34a;color:#16a34a;}.btn-download:hover{background:#f0fdf4;}
.btn-print{border-color:#0369a1;color:#0369a1;}.btn-print:hover{background:#f0f9ff;}
.era-summary{margin:0;padding:16px 20px 24px;display:flex;flex-direction:column;gap:16px;}
.era-sum-paid{color:#16a34a;font-weight:700;}
/* Header card */
.era-hdr-card{background:#fff;border:1px solid var(--border);border-radius:12px;padding:18px 22px;display:flex;flex-wrap:wrap;gap:0;align-items:stretch;}
.era-hdr-fields{display:flex;flex-wrap:wrap;gap:0;flex:1;}
.era-hdr-field{padding:0 28px 0 0;display:flex;flex-direction:column;gap:3px;}
.era-hdr-field:first-child{padding-left:0;}
.era-hdr-label{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--muted);}
.era-hdr-val{font-size:14px;font-weight:700;color:var(--text);}
.era-hdr-sub{font-size:11px;color:var(--muted);}
/* Stat tiles row */
.era-stats-row{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;}
.era-stat{background:#fff;border:1px solid var(--border);border-radius:10px;padding:14px 18px;}
.era-stat-label{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--muted);margin-bottom:4px;}
.era-stat-val{font-size:22px;font-weight:800;color:var(--text);line-height:1.1;}
.era-stat-val.green{color:#16a34a;}
.era-stat-sub{font-size:11px;color:var(--muted);margin-top:3px;}
/* Status badge */
.era-status-badge{display:inline-flex;align-items:center;gap:6px;padding:6px 14px;border-radius:20px;font-size:12px;font-weight:700;white-space:nowrap;align-self:flex-start;margin-left:auto;}
.badge-balanced{background:#dcfce7;color:#166534;}
.badge-partial{background:#fef9c3;color:#854d0e;}
.badge-unposted{background:#fee2e2;color:#991b1b;}
/* Claims table card */
.era-claims-card{background:#fff;border:1px solid var(--border);border-radius:12px;overflow:hidden;}
.era-claims-tbl{width:100%;border-collapse:collapse;font-size:12.5px;}
.era-claims-tbl thead tr{background:#f8fafc;border-bottom:2px solid var(--border);}
.era-claims-tbl th{padding:9px 14px;text-align:left;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--muted);white-space:nowrap;}
.era-claims-tbl th.r{text-align:right;}
.era-claims-tbl td{padding:12px 14px;border-bottom:1px solid #f3f4f6;vertical-align:middle;}
.era-claims-tbl td.r{text-align:right;font-variant-numeric:tabular-nums;}
.era-claims-tbl tbody tr:last-child td{border-bottom:none;}
.era-claims-tbl tbody tr.data-row:hover{background:#fafafa;}
.pt-name{font-weight:700;color:#0369a1;font-size:13px;}
.pt-check{font-size:10.5px;color:var(--muted);margin-top:2px;}
.val-green{color:#16a34a;font-weight:700;}
.val-red{color:#dc2626;font-weight:700;}
/* Status pills */
.spill{display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:600;white-space:nowrap;}
.spill-posted{background:#dcfce7;color:#166534;}
.spill-partial{background:#fef9c3;color:#854d0e;}
.spill-missing{background:#fee2e2;color:#991b1b;}
.spill-over{background:#faf5ff;color:#6b21a8;}
.spill-modifier{background:#eff6ff;color:#1d4ed8;}
/* Ledger / Edit Payment buttons */
.btn-ledger{display:inline-flex;align-items:center;gap:4px;padding:5px 12px;border:1.5px solid var(--border);border-radius:6px;font-size:11px;font-weight:600;color:var(--brand);background:#fff;cursor:pointer;text-decoration:none;transition:all .12s;white-space:nowrap;}
.btn-ledger:hover{background:#f0f9ff;border-color:#bae6fd;}
.btn-edit-pmt{border-color:#7c3aed;color:#7c3aed;}
.btn-edit-pmt:hover{background:#f5f3ff;border-color:#6d28d9;}
.btn-edit-pmt-hdr{display:inline-flex;align-items:center;gap:6px;padding:6px 16px;background:#7c3aed;color:#fff;border:none;border-radius:8px;font-size:12px;font-weight:700;cursor:pointer;transition:all .12s;white-space:nowrap;}
.btn-edit-pmt-hdr:hover{background:#6d28d9;}
.btn-edit-pmt.open{background:#f5f3ff;border-color:#6d28d9;}
/* Detail drawer */
.detail-row{display:none;}
.detail-row.open{display:table-row;}
.detail-inner{padding:14px 18px;background:#f8fafc;border-top:1px solid var(--border);}
/* Service line recon table (inside drawer) */
.recon-section-hdr{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--muted);margin-bottom:8px;display:flex;align-items:center;gap:5px;}
table.recon-table{width:100%;border-collapse:collapse;font-size:11.5px;}
table.recon-table th{padding:6px 10px;text-align:right;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:var(--muted);background:#fafafa;border-bottom:2px solid var(--border);white-space:nowrap;}
table.recon-table th:first-child{text-align:left;min-width:80px;}
table.recon-table th.grp-era{background:#eff6ff;color:#1d4ed8;border-bottom:2px solid #bfdbfe;}
table.recon-table th.grp-posted{background:#f0fdf4;color:#166534;border-bottom:2px solid #bbf7d0;}
table.recon-table th.grp-status{background:#faf5ff;color:#6b21a8;border-bottom:2px solid #e9d5ff;text-align:center;}
table.recon-table td{padding:6px 10px;text-align:right;border-bottom:1px solid #f3f4f6;vertical-align:middle;}
table.recon-table td:first-child{text-align:left;}
table.recon-table tr:last-child td{border-bottom:none;}
.era-cell{background:#eff6ff;}.posted-cell{background:#f0fdf4;}
.recon-match{background:#f0fdf4!important;}.recon-partial{background:#fffbeb!important;}
.recon-missing{background:#fef2f2!important;}.recon-over{background:#faf5ff!important;}
.recon-match td.s{color:#16a34a;font-weight:700;}.recon-partial td.s{color:#d97706;font-weight:700;}
.recon-missing td.s{color:#dc2626;font-weight:700;}.recon-over td.s{color:#7c3aed;font-weight:700;}
.cpt-code{font-weight:700;font-size:12px;}
.mod-badge{font-size:9px;background:#e5e7eb;border-radius:4px;padding:1px 4px;margin-left:3px;color:var(--muted);}
.claim-totals-row td{font-weight:700;background:#f8fafc!important;border-top:2px solid var(--border);font-size:12px;}
/* Legend */
.era-legend{background:#fff;border:1px solid var(--border);border-radius:10px;padding:14px 18px;}
.era-legend-title{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--muted);margin-bottom:10px;}
.era-legend-items{display:flex;flex-wrap:wrap;gap:18px;font-size:11px;}
.era-legend-items>div{display:flex;align-items:center;gap:7px;}
@media print{
  .era-header,.era-tabs,.era-sum-toolbar,.era-pane:not(.active){display:none!important;}
  .era-summary{margin:0;padding-bottom:0;}
  .era-sum-check,.era-sum-meta,.recon-strip{break-inside:avoid;border:1px solid #ccc!important;}
  .era-header,.era-tabs,.era-sum-toolbar,.era-toolbar{position:static!important;}
  table.recon-table th{position:static!important;}
  body{background:#fff!important;}
}
</style>
</head>
<body>

<div class="era-header">
  <h1>
    <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
    <?php echo xlt('ERA Viewer'); ?>
  </h1>
  <div class="era-meta">
    <?php if (!empty($session_row['ins_name'])): ?><span><strong><?php echo xlt('Payer'); ?>:</strong> <?php echo text($session_row['ins_name']); ?></span><?php endif; ?>
    <span><strong><?php echo xlt('Check'); ?>:</strong> <?php echo text($ref); ?></span>
    <?php if ($edi_base): ?><span><strong><?php echo xlt('File'); ?>:</strong> <?php echo text($edi_base); ?></span><?php endif; ?>
    <?php if (!empty($session_row['pay_total'])): ?><span><strong>$</strong><?php echo text(number_format($session_row['pay_total'],2)); ?></span><?php endif; ?>
    <?php if ($check_date): ?><span><?php echo text(oeFormatShortDate($check_date)); ?></span><?php endif; ?>
  </div>
</div>

<?php if ($found_anything): ?>

<div class="era-tabs">
  <?php if ($era_summary): ?><button class="era-tab active" onclick="showTab('summary',this)"><?php echo xlt('Summary');?></button><?php endif; ?>
  <?php if ($has_edi): ?><button class="era-tab<?php echo (!$era_summary)?' active':'';?>" onclick="showTab('edi-raw',this)"><?php echo xlt('Raw EDI');?><span class="era-tab-badge">835</span></button><?php endif; ?>
</div>

<?php if ($era_summary): ?>
<div class="era-pane active" id="pane-summary">
<div class="era-sum-topbar">
  <span style="font-size:11.5px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.05em;"><?php echo xlt('ERA Summary');?><?php if($check_date): ?> &mdash; <?php echo text(oeFormatShortDate($check_date));?><?php endif;?></span>
  <div style="display:flex;gap:8px;">
    <button class="btn-sum-action btn-print" onclick="eraPrint()">
      <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
      <?php echo xlt('Print');?>
    </button>
    <button class="btn-sum-action btn-download" onclick="eraDownload()">
      <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
      <?php echo xlt('Download');?>
    </button>
  </div>
</div>
<div class="era-summary" id="era-summary-content">

<?php
// Compute per-claim totals & status label for the table rows
function claim_row_label($cl, $posted_by_enc, $posted_lines) {
    $cl_era = [];
    foreach ($cl['svcs'] as $sv) {
        $k = $sv['code'] . ($sv['mod1'] ? '-'.$sv['mod1'] : '');
        if (!isset($cl_era[$k])) $cl_era[$k] = ['paid'=>0.0,'adj'=>0.0];
        $cl_era[$k]['paid'] += $sv['paid'];
        foreach ($sv['cas'] as $ca) {
            if ($ca['group'] === 'PR') continue;
            foreach ($ca['adjustments'] as $adj) $cl_era[$k]['adj'] += $adj['amount'];
        }
    }
    $cl_posted   = find_posted_for_claim($cl['clp_id'], $posted_by_enc, $posted_lines);
    $era_paid    = array_sum(array_column($cl_era,    'paid'));
    $post_paid   = array_sum(array_column($cl_posted, 'paid'));
    if ($era_paid == 0 && $post_paid == 0) return 'posted';
    if ($post_paid == 0 && $era_paid  > 0) return 'missing';
    if (abs($post_paid - $era_paid) < 0.02) {
        // check modifier mismatch
        $ek = array_map(fn($k)=>explode('-',$k)[0], array_keys($cl_era));
        $pk = array_map(fn($k)=>explode('-',$k)[0], array_keys($cl_posted));
        sort($ek); sort($pk);
        if ($ek === $pk && array_keys($cl_era) !== array_keys($cl_posted)) return 'modifier';
        return 'posted';
    }
    if (round($post_paid - $era_paid, 2) > 0.01) return 'over';
    return 'partial';
}

$pay_diff    = round($posted_total_paid - $era_total, 2);
$adj_diff    = round($posted_total_adj  - $era_summary['totals']['adjustments'], 2);
$era_overall = ($pay_diff == 0 && $adj_diff == 0) ? 'balanced' : (($posted_total_paid == 0 && $era_total > 0) ? 'unposted' : 'partial');
?>

<!-- ── Header card ─────────────────────────── -->
<div class="era-hdr-card">
  <div class="era-hdr-fields">
    <?php if(!empty($session_row['ins_name'])): ?>
    <div class="era-hdr-field">
      <span class="era-hdr-label"><?php echo xlt('Payer');?></span>
      <span class="era-hdr-val"><?php echo text($session_row['ins_name']);?></span>
    </div>
    <?php endif; ?>
    <div class="era-hdr-field">
      <span class="era-hdr-label"><?php echo xlt('Check / EFT');?></span>
      <span class="era-hdr-val"><?php echo text($ref);?></span>
    </div>
    <?php if($check_date): ?>
    <div class="era-hdr-field">
      <span class="era-hdr-label"><?php echo xlt('File Date');?></span>
      <span class="era-hdr-val"><?php echo text(oeFormatShortDate($check_date));?></span>
    </div>
    <?php endif; ?>
    <?php if(!empty($session_row['deposit_date']) && $session_row['deposit_date']!=='0000-00-00'): ?>
    <div class="era-hdr-field">
      <span class="era-hdr-label"><?php echo xlt('Deposit Date');?></span>
      <span class="era-hdr-val"><?php echo text(oeFormatShortDate($session_row['deposit_date']));?></span>
    </div>
    <?php endif; ?>
  </div>
  <?php
  $bc = ['balanced'=>'badge-balanced','partial'=>'badge-partial','unposted'=>'badge-unposted'][$era_overall];
  $bl = ['balanced'=>'✓ Balanced — All ERA payments posted','partial'=>'⚠ Partial — Some amounts differ','unposted'=>'✗ Unposted payments remain'][$era_overall];
  ?>
  <div style="display:flex;align-items:center;gap:10px;margin-left:auto;">
    <span class="era-status-badge <?php echo $bc;?>"><?php echo $bl;?></span>
    <?php if($session_id): ?>
    <button class="btn-edit-pmt-hdr" onclick="openEditPayment(<?php echo (int)$session_id;?>)">
      <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
      <?php echo xlt('Edit Payments'); ?>
    </button>
    <?php endif; ?>
  </div>
</div>

<!-- ── Stat tiles ──────────────────────────── -->
<div class="era-stats-row">
  <div class="era-stat">
    <div class="era-stat-label"><?php echo xlt('ERA Totals');?></div>
    <div class="era-stat-val green">$<?php echo number_format($era_total,2);?></div>
    <div class="era-stat-sub">$<?php echo number_format($era_summary['totals']['adjustments'],2);?> <?php echo xlt('Adjustments');?></div>
  </div>
  <div class="era-stat">
    <div class="era-stat-label"><?php echo xlt('Posted Totals');?></div>
    <div class="era-stat-val green">$<?php echo number_format($posted_total_paid,2);?></div>
    <div class="era-stat-sub">$<?php echo number_format($posted_total_adj,2);?> <?php echo xlt('Adjustments');?></div>
  </div>
  <div class="era-stat">
    <div class="era-stat-label"><?php echo xlt('Difference');?></div>
    <div class="era-stat-val <?php echo $pay_diff==0?'green':'';?>">$<?php echo number_format(abs($pay_diff),2);?></div>
    <div class="era-stat-sub">$<?php echo number_format(abs($adj_diff),2);?> <?php echo xlt('Adj Diff');?></div>
  </div>
  <div class="era-stat">
    <div class="era-stat-label"><?php echo xlt('Claims');?></div>
    <div class="era-stat-val"><?php echo (int)$era_summary['totals']['claims'];?></div>
    <div class="era-stat-sub"><?php echo xlt('across').' '.count($era_summary['checks']).' '.xlt('check(s)');?></div>
  </div>
</div>

<!-- ── Claims table ────────────────────────── -->
<div class="era-claims-card">
  <table class="era-claims-tbl" id="claims-tbl">
    <thead>
      <tr>
        <th><?php echo xlt('Patient');?></th>
        <th><?php echo xlt('Claim #');?></th>
        <th><?php echo xlt('DOS');?></th>
        <th class="r"><?php echo xlt('ERA Paid');?></th>
        <th class="r"><?php echo xlt('ERA Adj');?></th>
        <th class="r"><?php echo xlt('Posted Paid');?></th>
        <th class="r"><?php echo xlt('Posted Adj');?></th>
        <th><?php echo xlt('Status');?></th>
        <th><?php echo xlt('Ledger');?></th>
      </tr>
    </thead>
    <tbody>
    <?php
    $ri = 0;
    foreach ($era_summary['checks'] as $ck):
      foreach ($ck['claims'] as $cl):
        $ri++;
        $display_name = $cl['patient_name'] ?: $cl['subscriber_name'];

        // Build ERA service totals for this claim
        $cl_era = [];
        foreach ($cl['svcs'] as $sv) {
            $k = $sv['code'] . ($sv['mod1'] ? '-'.$sv['mod1'] : '');
            if (!isset($cl_era[$k])) $cl_era[$k] = ['code'=>$sv['code'],'mod'=>$sv['mod1'],'paid'=>0.0,'adj'=>0.0,'billed'=>0.0];
            $cl_era[$k]['paid']   += $sv['paid'];
            $cl_era[$k]['billed'] += $sv['billed'];
            foreach ($sv['cas'] as $ca) {
                if ($ca['group'] === 'PR') continue;
                foreach ($ca['adjustments'] as $adj) $cl_era[$k]['adj'] += $adj['amount'];
            }
        }
        $cl_posted    = find_posted_for_claim($cl['clp_id'], $posted_by_enc, $posted_lines);
        $era_paid_tot = array_sum(array_column($cl_era,    'paid'));
        $era_adj_tot  = array_sum(array_column($cl_era,    'adj'));
        $post_paid_tot= array_sum(array_column($cl_posted, 'paid'));
        $post_adj_tot = array_sum(array_column($cl_posted, 'adj'));

        $label = claim_row_label($cl, $posted_by_enc, $posted_lines);
        $pills = [
            'posted'   => ['spill-posted',  '✓', 'Posted'],
            'partial'  => ['spill-partial', '⚠', 'Partial'],
            'missing'  => ['spill-missing', '✗', 'Not Posted'],
            'over'     => ['spill-over',    '△', 'Overposted'],
            'modifier' => ['spill-modifier','M', 'Modifier Mismatch'],
        ][$label] ?? ['spill-missing','?','Exception'];

        // DOS — use first service line date if available
        $dos = '—';
        if (!empty($cl['svcs'][0]['dos'])) $dos = oeFormatShortDate($cl['svcs'][0]['dos']);
        elseif (!empty($cl['dos']))         $dos = oeFormatShortDate($cl['dos']);

        // Encounter for ledger link
        $enc_num = '';
        if (is_numeric($cl['clp_id'])) $enc_num = $cl['clp_id'];
        elseif (preg_match('/(\d+)$/', $cl['clp_id'], $em)) $enc_num = $em[1];
    ?>
    <tr class="data-row">
      <td>
        <div class="pt-name"><?php echo text($display_name);?></div>
        <div class="pt-check"><?php echo text($ck['trn']);?></div>
      </td>
      <td><code style="font-size:11px;"><?php echo text($cl['clp_id']);?></code></td>
      <td style="white-space:nowrap;"><?php echo $dos;?></td>
      <td class="r <?php echo $era_paid_tot>0?'val-green':'';?>">
        <?php echo $era_paid_tot>0 ? '$'.number_format($era_paid_tot,2) : '<span style="color:#9ca3af">—</span>'; ?>
      </td>
      <td class="r"><?php echo $era_adj_tot>0 ? '$'.number_format($era_adj_tot,2) : '<span style="color:#9ca3af">—</span>'; ?></td>
      <td class="r <?php echo $post_paid_tot>0?'val-green':($era_paid_tot>0?'val-red':'');?>">
        $<?php echo number_format($post_paid_tot,2);?>
      </td>
      <td class="r"><?php echo $post_adj_tot>0 ? '$'.number_format($post_adj_tot,2) : '<span style="color:#9ca3af">—</span>'; ?></td>
      <td><span class="spill <?php echo $pills[0];?>"><?php echo $pills[1].' '.xlt($pills[2]);?></span></td>
      <td style="white-space:nowrap;display:flex;flex-direction:column;gap:4px;">
        <button class="btn-ledger" onclick="toggleDetail(<?php echo $ri;?>)">
          Detail <svg width="10" height="10" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="6 9 12 15 18 9"/></svg>
        </button>

      </td>
    </tr>
    <!-- Edit Payment drawer -->
    <tr class="detail-row" id="edit-pmt-<?php echo $ri;?>">
      <td colspan="9" style="padding:0;">
        <div id="edit-pmt-frame-wrap-<?php echo $ri;?>" style="background:#f8fafc;border-top:1px solid var(--border);">
          <iframe id="edit-pmt-frame-<?php echo $ri;?>"
            src=""
            style="width:100%;height:0;border:none;display:block;transition:height .25s;"
            onload="resizeEditFrame(<?php echo $ri;?>)">
          </iframe>
        </div>
      </td>
    </tr>
    <!-- Detail drawer -->
    <tr class="detail-row" id="detail-<?php echo $ri;?>">
      <td colspan="9">
        <div class="detail-inner">
          <div style="font-size:11px;color:var(--muted);margin-bottom:10px;">
            <?php echo xlt('Billed');?>: <strong>$<?php echo number_format($cl['billed'],2);?></strong>
            &nbsp;|&nbsp;<?php echo xlt('ERA Paid');?>: <strong class="val-green">$<?php echo number_format($cl['paid'],2);?></strong>
            &nbsp;|&nbsp;<?php echo xlt('Pt Resp');?>: <strong>$<?php echo number_format($cl['patient_resp'],2);?></strong>
            <?php if($cl['payer_claim_id']): ?>&nbsp;|&nbsp;<?php echo xlt('Payer Claim');?>: <code style="font-size:10px;"><?php echo text($cl['payer_claim_id']);?></code><?php endif;?>
          </div>
          <?php if(!empty($cl['svcs'])): ?>
          <div class="recon-section-hdr">
            <svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>
            <?php echo xlt('Service Lines — ERA vs Posted');?>
          </div>
          <table class="recon-table">
            <thead>
              <tr>
                <th rowspan="2"><?php echo xlt('CPT');?></th>
                <th class="grp-era" colspan="3" style="text-align:center;border-right:2px solid #bfdbfe;"><?php echo xlt('ERA');?></th>
                <th class="grp-posted" colspan="2" style="text-align:center;border-right:2px solid #bbf7d0;"><?php echo xlt('Posted');?></th>
                <th class="grp-status" rowspan="2" style="text-align:center;"><?php echo xlt('Status');?></th>
              </tr>
              <tr>
                <th class="grp-era"><?php echo xlt('Paid');?></th>
                <th class="grp-era"><?php echo xlt('Adj');?></th>
                <th class="grp-era" style="border-right:2px solid #bfdbfe;"><?php echo xlt('Billed');?></th>
                <th class="grp-posted"><?php echo xlt('Paid');?></th>
                <th class="grp-posted" style="border-right:2px solid #bbf7d0;"><?php echo xlt('Adj');?></th>
              </tr>
            </thead>
            <tbody>
            <?php
            $all_keys = array_unique(array_merge(array_keys($cl_era), array_keys($cl_posted)));
            sort($all_keys);
            $cl_era_tot = $cl_post_tot = 0.0;
            foreach ($all_keys as $ckey):
                $sv_e=$cl_era[$ckey]??null; $sv_p=$cl_posted[$ckey]??null;
                if(!$sv_e&&!$sv_p) continue;
                $ep=$sv_e?$sv_e['paid']:0.0; $ea=$sv_e?$sv_e['adj']:0.0; $eb=$sv_e?$sv_e['billed']:0.0;
                $pp=$sv_p?$sv_p['paid']:0.0; $pa=$sv_p?$sv_p['adj']:0.0;
                $st=recon_status($ep,$pp);
                $pts=explode('-',$ckey,2);
                $cl_era_tot+=$ep; $cl_post_tot+=$pp;
            ?>
            <tr class="<?php echo $st['cls'];?>">
              <td><span class="cpt-code"><?php echo text($pts[0]);?></span><?php if(!empty($pts[1])): ?><span class="mod-badge"><?php echo text($pts[1]);?></span><?php endif;?></td>
              <td class="era-cell"><?php echo $ep?'$'.number_format($ep,2):'<span style="color:#9ca3af">—</span>';?></td>
              <td class="era-cell"><?php echo $ea?'$'.number_format($ea,2):'<span style="color:#9ca3af">—</span>';?></td>
              <td class="era-cell" style="border-right:2px solid #bfdbfe;"><?php echo $eb?'$'.number_format($eb,2):'<span style="color:#9ca3af">—</span>';?></td>
              <td class="posted-cell"><?php echo $pp?'$'.number_format($pp,2):'<span style="color:#9ca3af">—</span>';?></td>
              <td class="posted-cell" style="border-right:2px solid #bbf7d0;"><?php echo $pa?'$'.number_format($pa,2):'<span style="color:#9ca3af">—</span>';?></td>
              <td class="s" style="text-align:center;"><?php echo $st['icon'].' '.text($st['label']);?></td>
            </tr>
            <?php endforeach; ?>
            <tr class="claim-totals-row">
              <td><?php echo xlt('Totals');?></td>
              <td class="era-cell val-green">$<?php echo number_format($cl_era_tot,2);?></td>
              <td class="era-cell">—</td>
              <td class="era-cell" style="border-right:2px solid #bfdbfe;">—</td>
              <td class="posted-cell val-green">$<?php echo number_format($cl_post_tot,2);?></td>
              <td class="posted-cell" style="border-right:2px solid #bbf7d0;">—</td>
              <?php $tot_st=recon_status($cl_era_tot,$cl_post_tot);?>
              <td class="s <?php echo $tot_st['cls'];?>" style="text-align:center;"><?php echo $tot_st['icon'];?></td>
            </tr>
            </tbody>
          </table>
          <?php elseif(!empty($cl['cas'])): ?>
          <div class="recon-section-hdr"><?php echo xlt('Claim Adjustments');?></div>
          <table class="recon-table">
            <thead><tr><th style="text-align:left;"><?php echo xlt('Group');?></th><th><?php echo xlt('Reason');?></th><th><?php echo xlt('Amount');?></th></tr></thead>
            <tbody>
            <?php foreach($cl['cas'] as $ca): foreach($ca['adjustments'] as $adj): ?>
            <tr><td><?php echo text(era_cas_group($ca['group']));?></td><td><?php echo text($adj['reason']);?></td><td>$<?php echo number_format($adj['amount'],2);?></td></tr>
            <?php endforeach; endforeach; ?>
            </tbody>
          </table>
          <?php endif; ?>
        </div>
      </td>
    </tr>
    <?php endforeach; endforeach; ?>
    </tbody>
  </table>
</div>

<!-- Legend -->
<div class="era-legend">
  <div class="era-legend-title"><?php echo xlt('Status Legend');?></div>
  <div class="era-legend-items">
    <div><span class="spill spill-posted">✓ <?php echo xlt('Posted');?></span> <span style="color:var(--muted);"><?php echo xlt('ERA payment and adjustments were posted');?></span></div>
    <div><span class="spill spill-modifier">M <?php echo xlt('Modifier Mismatch');?></span> <span style="color:var(--muted);"><?php echo xlt('Payment posted with different modifier');?></span></div>
    <div><span class="spill spill-partial">⚠ <?php echo xlt('Partial');?></span> <span style="color:var(--muted);"><?php echo xlt('Partial payment or adjustments posted');?></span></div>
    <div><span class="spill spill-missing">✗ <?php echo xlt('Not Posted');?></span> <span style="color:var(--muted);"><?php echo xlt('No payment or adjustments posted');?></span></div>
    <div><span class="spill spill-over">△ <?php echo xlt('Exception');?></span> <span style="color:var(--muted);"><?php echo xlt('Other differences require review');?></span></div>
  </div>
  <div style="margin-top:10px;font-size:11px;color:var(--muted);">💡 <?php echo xlt('Tip: Click "Edit Payment" to edit this payment inline. Click "Detail" to see service line reconciliation.');?></div>
</div>

</div><!-- /era-summary-content -->
</div><!-- /pane-summary -->
<?php endif; ?>

<?php if ($has_edi): ?>
<div class="era-pane<?php echo (!$era_summary)?' active':'';?>" id="pane-edi-raw">
  <div class="era-toolbar">
    <label for="era-search"><?php echo xlt('Find');?></label>
    <input type="text" id="era-search" placeholder="<?php echo xla('Search EDI...');?>" oninput="searchEra()" onkeydown="if(event.key==='Enter')nextMatch()"/>
    <button class="btn-hl" onclick="nextMatch()"><?php echo xlt('Next');?></button>
    <span class="match-count" id="match-count"></span>
    <span class="era-filename"><?php echo text($edi_filename);?></span>
  </div>
  <pre id="era-content"><?php echo htmlspecialchars($edi_file, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8');?></pre>
</div>
<?php endif; ?>

<?php else: // NOT FOUND — show full diagnostics ?>

<div class="era-not-found">
  <h2><?php echo xlt('ERA file not found for check number:'); ?> <code style="font-size:14px;"><?php echo text($check_num);?></code></h2>

  <table class="debug-table">
    <tr><th colspan="2"><?php echo xlt('Directory');?></th></tr>
    <tr><td><?php echo xlt('Path');?></td><td><?php echo text($debug['era_dir']);?></td></tr>
    <tr><td><?php echo xlt('Exists');?></td><td class="<?php echo $debug['dir_exists']?'ok':'fail';?>"><?php echo $debug['dir_exists']?'YES':'NO — check OE_SITE_DIR global';?></td></tr>
    <tr><td><?php echo xlt('Readable');?></td><td class="<?php echo $debug['dir_readable']?'ok':'fail';?>"><?php echo $debug['dir_readable']?'YES':'NO — permission issue';?></td></tr>
    <tr><td><?php echo xlt('Writable (index)');?></td><td class="<?php echo $debug['dir_writable']?'ok':'fail';?>"><?php echo $debug['dir_writable']?'YES':'NO — index cannot be saved (still works in memory)';?></td></tr>
    <tr><td><?php echo xlt('Index file exists');?></td><td><?php echo $debug['idx_exists']?'YES':'NO (will be created on first match)';?></td></tr>
    <tr><td><?php echo xlt('OE_SITE_DIR raw');?></td><td><?php echo text($debug['oe_site_dir_raw']);?></td></tr>
    <tr><td><?php echo xlt('DOCUMENT_ROOT');?></td><td><?php echo text($debug['doc_root']);?></td></tr>
    <tr><td><?php echo xlt('This file (__FILE__)');?></td><td><?php echo text($debug['this_file']);?></td></tr>
    <?php if (!empty($debug['index_count'])): ?>
    <tr><td><?php echo xlt('Index entries');?></td><td><?php echo (int)$debug['index_count'];?></td></tr>
    <?php endif; ?>
  </table>

  <?php if (!empty($debug['edi_files'])): ?>
  <table class="debug-table" style="margin-top:14px;">
    <tr><th><?php echo xlt('EDI File');?></th><th><?php echo xlt('TRN Check#');?></th><th><?php echo xlt('Readable');?></th><th><?php echo xlt('Matches?');?></th></tr>
    <?php foreach ($debug['edi_files'] as $ef):
        $matches_check = in_array($check_num, $ef['trns'] ?? []);
    ?>
    <tr>
      <td><?php echo text($ef['file']);?></td>
      <td><?php echo $ef['trn'] ? text($ef['trn']) : '<span class="fail">NOT FOUND</span>';?></td>
      <td class="<?php echo $ef['readable']?'ok':'fail';?>"><?php echo $ef['readable']?'YES':'NO';?></td>
      <td class="<?php echo $matches_check?'ok':'';?>"><?php echo $matches_check?'✓ YES':'—';?></td>
    </tr>
    <?php endforeach; ?>
  </table>
  <?php elseif ($debug['dir_exists']): ?>
  <p style="margin-top:12px;font-size:11.5px;color:#c2410c;"><?php echo xlt('No .edi, .835, or .x12 files found in the directory.');?></p>
  <?php endif; ?>

  <?php if (!empty($GLOBALS['_era_candidates_tried'])): ?>
  <table class="debug-table" style="margin-top:14px;">
    <tr><th colspan="4"><?php echo xlt('Paths Tried');?></th></tr>
    <tr><th><?php echo xlt('Path');?></th><th><?php echo xlt('Realpath');?></th><th><?php echo xlt('Exists');?></th><th><?php echo xlt('Readable');?></th></tr>
    <?php foreach ($GLOBALS['_era_candidates_tried'] as $ct): ?>
    <tr>
      <td style="word-break:break-all;"><?php echo text($ct['path']);?></td>
      <td style="word-break:break-all;"><?php echo text($ct['realpath']);?></td>
      <td class="<?php echo $ct['exists']?'ok':'fail';?>"><?php echo $ct['exists']?'YES':'NO';?></td>
      <td class="<?php echo $ct['readable']?'ok':'fail';?>"><?php echo $ct['readable']?'YES':'NO';?></td>
    </tr>
    <?php endforeach; ?>
  </table>
  <?php endif; ?>
  <a class="btn-rebuild" href="?ref=<?php echo attr_url($ref);?>&session_id=<?php echo attr_url($session_id);?>&rebuild=1">
    &#x21BA; <?php echo xlt('Force Rebuild Index &amp; Retry');?>
  </a>
</div>

<?php endif; ?>

<script>
function toggleDetail(n){
  var r=document.getElementById('detail-'+n);
  if(!r)return;
  r.classList.toggle('open');
}
function openEditPayment(sessionId) {
  var base = '<?php echo $GLOBALS["webroot"]; ?>';
  var url  = base + '/interface/billing/edit_payment.php?payment_id=' + sessionId + '&embedded=1';
  try { top.restoreSession(); } catch(e) {}
  try {
    top.dlgopen(url, 'edit_pmt_' + sessionId, 1050, 700, '', 'Edit Payments', {
      allowResize: false, allowDrag: false, type: 'iframe'
    });
  } catch(e) {
    window.open(base + '/interface/billing/edit_payment.php?payment_id=' + sessionId, '_blank');
  }
}
function resizeEditFrame(ri) {
  var frame = document.getElementById('edit-pmt-frame-' + ri);
  if (!frame) return;
  try {
    var h = frame.contentDocument.body.scrollHeight;
    if (h > 100) frame.style.height = Math.min(h + 20, 700) + 'px';
  } catch(e) {}
}
function showTab(id,btn){
  document.querySelectorAll('.era-pane').forEach(function(p){p.classList.remove('active');});
  document.querySelectorAll('.era-tab').forEach(function(t){t.classList.remove('active');});
  var p=document.getElementById('pane-'+id);if(p)p.classList.add('active');
  btn.classList.add('active');
}
function eraPrint(){window.print();}
function eraDownload(){
  var content=document.getElementById('era-summary-content');
  if(!content)return;
  var payer=<?php echo json_encode($era_summary?$era_summary['payer']:'');?>;
  var check=<?php echo json_encode($ref);?>;
  var date=<?php echo json_encode($check_date?:date('Y-m-d'));?>;
  var styles=Array.from(document.querySelectorAll('style')).map(function(s){return s.outerHTML;}).join('');
  var html='<!DOCTYPE html><html><head><meta charset="utf-8"><title>ERA Summary \u2013 '+check+'<\/title>'+styles+'<\/head>'
    +'<body style="background:#f8fafc;padding:16px;">'
    +'<div style="font-size:12px;color:#6b7280;margin-bottom:14px;">'
    +(payer?'Payer: <strong>'+payer+'<\/strong> &nbsp;&bull;&nbsp; ':'')
    +'Check: <strong>'+check+'<\/strong>'+(date?' &nbsp;&bull;&nbsp; Date: <strong>'+date+'<\/strong>':'')
    +'<\/div>'+content.outerHTML+'<\/body><\/html>';
  var blob=new Blob([html],{type:'text/html'});
  var a=document.createElement('a');
  a.href=URL.createObjectURL(blob);
  a.download='ERA_Summary_'+check.replace(/[^a-zA-Z0-9_-]/g,'_')+'.html';
  document.body.appendChild(a);a.click();
  setTimeout(function(){document.body.removeChild(a);URL.revokeObjectURL(a.href);},500);
}
(function(){
  var pre=document.getElementById('era-content');
  if(!pre)return;
  if(/ISA\*/.test(pre.textContent)){
    var segs=pre.textContent.split(/~[\r\n]?/);
    pre.innerHTML=segs.map(function(seg){
      var s=seg.replace(/^[\r\n]+/,'');if(!s.trim())return'';
      var tag=s.split('*')[0].trim();
      return'<span class="seg-'+tag+'">'+s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')+'</span>';
    }).join('~\n');
  }
  var matches=[],cur=-1;
  window.searchEra=function(){
    var t=document.getElementById('era-search').value,c=document.getElementById('match-count');
    pre.innerHTML=pre.innerHTML.replace(/<mark class="era-hl(?:-cur)?">([^<]*)<\/mark>/g,'$1');
    if(!t){c.textContent='';matches=[];cur=-1;return;}
    var re=new RegExp('('+t.replace(/[.*+?^${}()|[\]\\]/g,'\\$&')+')','gi'),n=0;
    pre.innerHTML=pre.innerHTML.replace(re,function(m){n++;return'<mark class="era-hl">'+m+'</mark>';});
    c.textContent=n?n+' <?php echo xlt('matches');?>':'<?php echo xlt('No matches');?>';
    matches=Array.from(pre.querySelectorAll('mark.era-hl'));cur=-1;if(matches.length)nextMatch();
  };
  window.nextMatch=function(){
    if(!matches.length)return;
    if(cur>=0&&matches[cur])matches[cur].className='era-hl';
    cur=(cur+1)%matches.length;
    matches[cur].className='era-hl era-hl-cur';
    matches[cur].scrollIntoView({block:'center',behavior:'smooth'});
    document.getElementById('match-count').textContent=(cur+1)+'/'+matches.length+' <?php echo xlt('matches');?>';
  };
})();
</script>
</body>
</html>