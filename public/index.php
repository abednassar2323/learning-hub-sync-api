<?php
declare(strict_types=1);

header('Content-Type: application/json');

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if ($path === '/' || $path === '') {
    echo json_encode([
        'service' => 'learning-hub-sync-api',
        'status' => 'ok'
    ]);
    exit;
}

if ($path === '/health') {
    echo json_encode([
        'status' => 'healthy',
        'time' => date('c')
    ]);
    exit;
}

http_response_code(404);
echo json_encode([
    'error' => 'Not Found'
]);
