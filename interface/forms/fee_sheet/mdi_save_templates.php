<?php

require_once(__DIR__ . "/../../globals.php");

use OpenEMR\Common\Csrf\CsrfUtils;

header('Content-Type: application/json');

// CSRF check
if (!CsrfUtils::verifyCsrfToken($_POST['csrf_token_form'] ?? '')) {
    echo json_encode([
        'success' => false,
        'error'   => 'Invalid CSRF token.'
    ]);
    exit;
}

$templates = json_decode($_POST['templates'] ?? '[]', true);

if (!is_array($templates)) {
    echo json_encode([
        'success' => false,
        'error'   => 'Invalid template data.'
    ]);
    exit;
}

// Replace all templates
sqlStatement("UPDATE mdi_quick_charge_templates
SET active = 0");

foreach ($templates as $tpl) {

    sqlStatement(
        "INSERT INTO mdi_quick_charge_templates
            (name, template_json, active)
         VALUES (?, ?, 1)",
        [
            $tpl['name'] ?? '',
            json_encode($tpl)
        ]
    );
}

echo json_encode([
    'success' => true
]);

exit;