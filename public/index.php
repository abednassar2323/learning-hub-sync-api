<?php
declare(strict_types=1);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path   = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';

function json_out(int $code, array $data): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_PRETTY_PRINT);
    exit;
}

function bearer_token(): string {
    $h = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (stripos($h, 'Bearer ') === 0) {
        return trim(substr($h, 7));
    }
    return '';
}

function require_auth(): void {
    $expected = $_ENV['SYNC_API_KEY'] ?? getenv('SYNC_API_KEY') ?: '';
    if ($expected === '') {
        json_out(500, ['error' => 'SYNC_API_KEY not configured']);
    }

    $token = bearer_token();
    if ($token === '' || !hash_equals($expected, $token)) {
        json_out(401, ['error' => 'Unauthorized']);
    }
}

function get_pdo(): PDO {
    $host = $_ENV['MYSQLHOST'] ?? getenv('MYSQLHOST');
    $port = $_ENV['MYSQLPORT'] ?? getenv('MYSQLPORT') ?: 3306;
    $db   = $_ENV['MYSQLDATABASE'] ?? getenv('MYSQLDATABASE');
    $user = $_ENV['MYSQLUSER'] ?? getenv('MYSQLUSER');
    $pass = $_ENV['MYSQLPASSWORD'] ?? getenv('MYSQLPASSWORD');

    if (!$host || !$db || !$user) {
        json_out(500, ['error' => 'MySQL environment variables missing']);
    }

    return new PDO(
        "mysql:host=$host;port=$port;dbname=$db;charset=utf8mb4",
        $user,
        $pass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]
    );
}

function ensure_table(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS sync_snapshots (
            id INT AUTO_INCREMENT PRIMARY KEY,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            sha256 VARCHAR(64) NOT NULL,
            size_bytes INT NOT NULL,
            sqlite_blob LONGBLOB NOT NULL
        )
    ");
}

/* ===============================
   ROUTES
================================ */

// GET /
if ($method === 'GET' && ($path === '/' || $path === '')) {
    json_out(200, [
        'service' => 'learning-hub-sync-api',
        'status'  => 'ok'
    ]);
}

// GET /health
if ($method === 'GET' && $path === '/health') {
    json_out(200, [
        'status' => 'healthy',
        'time'   => date('c')
    ]);
}

// POST /push
if ($method === 'POST' && $path === '/push') {
    require_auth();

    if (!isset($_FILES['db']) || !is_uploaded_file($_FILES['db']['tmp_name'])) {
        json_out(400, ['error' => 'Missing file field "db"']);
    }

    $bytes = file_get_contents($_FILES['db']['tmp_name']);
    if ($bytes === false) {
        json_out(500, ['error' => 'Failed to read uploaded file']);
    }

    $size = strlen($bytes);
    $sha  = hash('sha256', $bytes);

    $pdo = get_pdo();
    ensure_table($pdo);

    $stmt = $pdo->prepare(
        "INSERT INTO sync_snapshots (sha256, size_bytes, sqlite_blob) VALUES (?, ?, ?)"
    );
    $stmt->execute([$sha, $size, $bytes]);

    json_out(200, [
        'status'     => 'ok',
        'sha256'     => $sha,
        'size_bytes' => $size
    ]);
}

// GET /pull
if ($method === 'GET' && $path === '/pull') {
    require_auth();

    $pdo = get_pdo();
    ensure_table($pdo);

    $stmt = $pdo->query(
        "SELECT sha256, size_bytes, sqlite_blob FROM sync_snapshots ORDER BY id DESC LIMIT 1"
    );

    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        json_out(404, ['error' => 'No snapshot available']);
    }

    header('Content-Type: application/x-sqlite3');
    header('Content-Disposition: attachment; filename="learninghub.sqlite"');
    header('X-SHA256: ' . $row['sha256']);
    header('X-Size-Bytes: ' . $row['size_bytes']);

    echo $row['sqlite_blob'];
    exit;
}

// Fallback
json_out(404, [
    'error'  => 'Not Found',
    'path'   => $path,
    'method' => $method
]);
