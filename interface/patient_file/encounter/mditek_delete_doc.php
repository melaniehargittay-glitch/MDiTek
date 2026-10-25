<?php
/**
 * mditek_delete_doc.php
 * Soft-deletes an imaging document.
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

if (!AclMain::aclCheckCore('patients', 'med'))                      { json_out(['success'=>false,'error'=>'Access denied']); }
if (!CsrfUtils::verifyCsrfToken($_POST['csrf_token_form'] ?? ''))   { json_out(['success'=>false,'error'=>'CSRF']); }

$pid   = (int)($_POST['pid']         ?? 0);
$docId = (int)($_POST['document_id'] ?? 0);

// Debug: return exactly what we received if validation fails
if (!$pid)   { json_out(['success'=>false,'error'=>'Missing pid (received: '.($_POST['pid']??'[none]').')']); }
if (!$docId) { json_out(['success'=>false,'error'=>'Missing document_id (received: '.($_POST['document_id']??'[none]').')']); }

$row = sqlQuery(
    "SELECT id FROM documents WHERE id=? AND foreign_id=? AND deleted=0 LIMIT 1",
    [$docId, $pid]
);
if (empty($row)) { json_out(['success'=>false,'error'=>"Document $docId not found for patient $pid"]); }

sqlStatement("UPDATE documents SET deleted=1 WHERE id=? AND foreign_id=?", [$docId, $pid]);
json_out(['success'=>true,'message'=>'Document deleted']);
