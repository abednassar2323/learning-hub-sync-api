<?php
declare(strict_types=1);

header('Content-Type: application/json');

function require_api_key(): void {
    $need = getenv('API_KEY') ?: '';
    if ($need === '') return; // not recommended, but prevents lockout
    $got = $_SERVER['HTTP_X_API_KEY'] ?? '';
    if (!hash_equals($need, $got)) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }
}

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if ($path === '/' || $path === '/health') {
    echo json_encode(['status'=>'ok','service'=>'learning-hub-sync-api','time'=>date('c')]);
    exit;
}

require_api_key();
require __DIR__ . '/../src/db.php';

if ($path === '/setup' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require __DIR__ . '/../src/setup_cloud.php';
    try {
        setup_cloud($pdo);
        echo json_encode(['ok'=>true, 'message'=>'Cloud tables created']);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok'=>false, 'error'=>'Setup failed', 'message'=>$e->getMessage()]);
    }
    exit;
}

http_response_code(404);
echo json_encode(['error'=>'Not found']);
