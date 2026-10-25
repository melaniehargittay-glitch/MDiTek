<?php
require_once(__DIR__ . "/../globals.php");

$q = $_GET['q'] ?? '';

$res = sqlStatement(
    "SELECT code, code_text, fee 
     FROM codes 
     WHERE code_type = 'CPT4'
     AND (code LIKE ? OR code_text LIKE ?)
     ORDER BY code
     LIMIT 20",
    ["$q%", "%$q%"]
);

$out = [];

while ($row = sqlFetchArray($res)) {
    $out[] = [
        'code' => $row['code'],
        'text' => $row['code_text'],
        'fee'  => $row['fee'] ?? ''
    ];
}

header('Content-Type: application/json');
echo json_encode($out);