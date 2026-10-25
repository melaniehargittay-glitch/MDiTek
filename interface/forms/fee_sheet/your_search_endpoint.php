<?php
require_once(__DIR__ . "/../../globals.php");

$search_type = $_POST['search_type'];
$search_term = $_POST['search_term'];

// Perform your database search based on $search_type and $search_term
$res = main_code_set_search($search_type, $search_term);

$results = [];
while ($row = sqlFetchArray($res)) {
    $results[] = [
        'code' => $row['code'],
        'modifier' => $row['modifier'],
        'code_text' => $row['code_text'],
        'code_type' => $search_type
    ];
}

header('Content-Type: application/json');
echo json_encode($results);