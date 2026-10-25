<?php
/**
 * mditek_save_annotations.php
 * Composites freehand annotation strokes onto the source image using GD
 * and saves the result as a new document linked to the encounter.
 */
ob_start();
require_once(__DIR__ . "/../../globals.php");
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Acl\AclMain;

function json_out(array $d): void {
    ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($d);
    exit;
}

if (!AclMain::aclCheckCore('patients', 'med'))                     { json_out(['success'=>false,'error'=>'Access denied']); }
if (!CsrfUtils::verifyCsrfToken($_POST['csrf_token_form'] ?? '')) { json_out(['success'=>false,'error'=>'CSRF']); }

$pid            = (int)($_POST['pid']            ?? 0);
$encounter      = (int)($_POST['encounter']      ?? 0);
$annotationJson = $_POST['annotation_json']      ?? '[]';

if (!$pid || !$encounter) { json_out(['success'=>false,'error'=>'Missing pid or encounter']); }

$annotations = json_decode($annotationJson, true);
if (!is_array($annotations) || empty($annotations)) { json_out(['success'=>false,'error'=>'No annotation data']); }
if (!function_exists('imagecreatefrompng')) { json_out(['success'=>false,'error'=>'GD not available']); }

// Group paths by doc_id
$byDoc = [];
foreach ($annotations as $a) {
    $docId = (int)($a['doc_id'] ?? 0);
    if ($docId && !empty($a['points'])) { $byDoc[$docId][] = $a['points']; }
}

function load_gd(array $row): ?\GdImage {
    $url  = $row['url'] ?? '';
    $path = $GLOBALS['OE_SITE_DIR'] . preg_replace('#^/sites/[^/]+#', '', $url);
    if (!is_readable($path)) { return null; }
    $mt  = strtolower((string)($row['mimetype'] ?? ''));
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    if ($mt==='image/jpeg'||$ext==='jpg'||$ext==='jpeg') return @imagecreatefromjpeg($path)?:null;
    if ($mt==='image/png' ||$ext==='png')  return @imagecreatefrompng($path)?:null;
    if ($mt==='image/gif' ||$ext==='gif')  return @imagecreatefromgif($path)?:null;
    if (($mt==='image/webp'||$ext==='webp') && function_exists('imagecreatefromwebp')) return @imagecreatefromwebp($path)?:null;
    return null;
}

$savedIds = [];
foreach ($byDoc as $docId => $pathsArr) {
    $srcDoc = sqlQuery("SELECT id,url,mimetype,name,foreign_id FROM documents WHERE id=? AND foreign_id=? AND deleted=0 LIMIT 1", [$docId,$pid]);
    if (empty($srcDoc)) { continue; }

    $im = load_gd($srcDoc);
    if ($im === null) { continue; }

    $red = imagecolorallocate($im, 255, 82, 82);
    imagesetthickness($im, max(1,(int)round(imagesx($im)/400)));

    foreach ($pathsArr as $points) {
        for ($i=1; $i<count($points); $i++) {
            imageline($im,
                (int)round($points[$i-1]['x']), (int)round($points[$i-1]['y']),
                (int)round($points[$i]['x']),   (int)round($points[$i]['y']),
                $red);
        }
    }

    $dir  = $GLOBALS['OE_SITE_DIR'].'/documents/imaging/'.$pid;
    if (!is_dir($dir)) { mkdir($dir,0775,true); }
    $base = pathinfo((string)$srcDoc['name'], PATHINFO_FILENAME);
    $file = time().'_annotated_'.preg_replace('/[^a-zA-Z0-9._-]/','_',$base).'.png';
    $path = $dir.'/'.$file;

    if (!imagepng($im, $path, 6)) { imagedestroy($im); continue; }
    imagedestroy($im);

    $docUrl = '/sites/'.($_SESSION['site_id']??'default').'/documents/imaging/'.$pid.'/'.$file;
    sqlStatement(
        "INSERT INTO documents (type,size,date,url,mimetype,owner,foreign_id,docdate,name,encounter_id,deleted)
         VALUES ('file_url',?,NOW(),?,'image/png',?,?,CURDATE(),?,?,0)",
        [(int)filesize($path), $docUrl, $_SESSION['authUserID']??1, $pid, $base.' (Annotated)', $encounter]
    );
    $newId = (int)sqlLastInsertId();

    $cat = sqlQuery("SELECT id FROM categories WHERE name='Medical Record' LIMIT 1");
    if (empty($cat)) { $cat = sqlQuery("SELECT id FROM categories WHERE name='Medical Records' LIMIT 1"); }
    $catId = !empty($cat['id']) ? (int)$cat['id'] : 1;
    sqlStatement("INSERT INTO categories_to_documents (category_id,document_id) VALUES (?,?) ON DUPLICATE KEY UPDATE category_id=category_id", [$catId,$newId]);

    $savedIds[] = $newId;
}

if (empty($savedIds)) { json_out(['success'=>false,'error'=>'Could not annotate (unsupported format or file not found)']); }

json_out(['success'=>true,'message'=>count($savedIds).' annotation(s) saved as new document(s)','document_ids'=>$savedIds]);
