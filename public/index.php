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
    if (stripos($h, 'Bearer ') === 0) return trim(substr($h, 7));
    return '';
}

function require_auth(): void {
    $expected = $_ENV['SYNC_API_KEY'] ?? getenv('SYNC_API_KEY') ?: '';
    if ($expected === '') json_out(500, ['error' => 'SYNC_API_KEY not configured']);
    $token = bearer_token();
    if ($token === '' || !hash_equals($expected, $token)) json_out(401, ['error' => 'Unauthorized']);
}

function get_pdo(): PDO {
    $host = $_ENV['MYSQLHOST'] ?? getenv('MYSQLHOST');
    $port = $_ENV['MYSQLPORT'] ?? getenv('MYSQLPORT') ?: 3306;
    $db   = $_ENV['MYSQLDATABASE'] ?? getenv('MYSQLDATABASE');
    $user = $_ENV['MYSQLUSER'] ?? getenv('MYSQLUSER');
    $pass = $_ENV['MYSQLPASSWORD'] ?? getenv('MYSQLPASSWORD');

    if (!$host || !$db || !$user) json_out(500, ['error' => 'MySQL environment variables missing']);

    return new PDO(
        "mysql:host=$host;port=$port;dbname=$db;charset=utf8mb4",
        $user,
        $pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
}

function ensure_db_table(PDO $pdo): void {
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

function ensure_uploads_table(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS uploads_snapshots (
            id INT AUTO_INCREMENT PRIMARY KEY,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            sha256 VARCHAR(64) NOT NULL,
            size_bytes INT NOT NULL,
            zip_blob LONGBLOB NOT NULL
        )
    ");
}

/* NEW: meta table to track last push */
function ensure_meta_table(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS sync_meta (
            id INT PRIMARY KEY DEFAULT 1,

            last_db_pushed_by VARCHAR(255) NULL,
            last_db_pushed_at VARCHAR(40) NULL,
            last_db_sha256 VARCHAR(64) NULL,
            last_db_size_bytes INT NULL,

            last_uploads_pushed_by VARCHAR(255) NULL,
            last_uploads_pushed_at VARCHAR(40) NULL,
            last_uploads_sha256 VARCHAR(64) NULL,
            last_uploads_size_bytes INT NULL,

            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )
    ");

    // Ensure a single row always exists (id=1)
    $pdo->exec("INSERT IGNORE INTO sync_meta (id) VALUES (1)");
}

function read_meta(PDO $pdo): array {
    ensure_meta_table($pdo);
    $stmt = $pdo->query("SELECT * FROM sync_meta WHERE id = 1");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: [];
}

/* ===============================
   ROUTES
================================ */

if ($method === 'GET' && ($path === '/' || $path === '')) {
    json_out(200, ['service' => 'learning-hub-sync-api', 'status' => 'ok']);
}

if ($method === 'GET' && $path === '/health') {
    json_out(200, ['status' => 'healthy', 'time' => date('c')]);
}

/* NEW: GET /last_sync (shows last push info for DB + uploads) */
if ($method === 'GET' && $path === '/last_sync') {
    require_auth();
    $pdo = get_pdo();
    $meta = read_meta($pdo);

    if (!$meta) {
        json_out(200, ['message' => 'No sync meta yet']);
    }

    json_out(200, [
        'last_db' => [
            'by' => $meta['last_db_pushed_by'] ?? null,
            'at' => $meta['last_db_pushed_at'] ?? null,
            'sha256' => $meta['last_db_sha256'] ?? null,
            'size_bytes' => isset($meta['last_db_size_bytes']) ? (int)$meta['last_db_size_bytes'] : null,
        ],
        'last_uploads' => [
            'by' => $meta['last_uploads_pushed_by'] ?? null,
            'at' => $meta['last_uploads_pushed_at'] ?? null,
            'sha256' => $meta['last_uploads_sha256'] ?? null,
            'size_bytes' => isset($meta['last_uploads_size_bytes']) ? (int)$meta['last_uploads_size_bytes'] : null,
        ],
        'updated_at' => $meta['updated_at'] ?? null,
    ]);
}

// POST /push (DB)
if ($method === 'POST' && $path === '/push') {
    require_auth();

    if (!isset($_FILES['db']) || !is_uploaded_file($_FILES['db']['tmp_name'])) {
        json_out(400, ['error' => 'Missing file field "db"']);
    }

    $bytes = file_get_contents($_FILES['db']['tmp_name']);
    if ($bytes === false) json_out(500, ['error' => 'Failed to read uploaded file']);

    $size = strlen($bytes);
    $sha  = hash('sha256', $bytes);

    $pdo = get_pdo();
    ensure_db_table($pdo);

    $stmt = $pdo->prepare("INSERT INTO sync_snapshots (sha256, size_bytes, sqlite_blob) VALUES (?, ?, ?)");
    $stmt->execute([$sha, $size, $bytes]);

    // NEW: write meta
    ensure_meta_table($pdo);
    $device = trim((string)($_POST['device_name'] ?? 'Unknown'));
    $pushed = trim((string)($_POST['pushed_at'] ?? date('c')));

    $stmt2 = $pdo->prepare("
        UPDATE sync_meta
        SET last_db_pushed_by = ?,
            last_db_pushed_at = ?,
            last_db_sha256 = ?,
            last_db_size_bytes = ?
        WHERE id = 1
    ");
    $stmt2->execute([$device, $pushed, $sha, $size]);

    json_out(200, [
        'status' => 'ok',
        'sha256' => $sha,
        'size_bytes' => $size,
        'last_db' => ['by' => $device, 'at' => $pushed]
    ]);
}

// GET /pull (DB)
if ($method === 'GET' && $path === '/pull') {
    require_auth();

    $pdo = get_pdo();
    ensure_db_table($pdo);

    $stmt = $pdo->query("SELECT sha256, size_bytes, sqlite_blob FROM sync_snapshots ORDER BY id DESC LIMIT 1");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) json_out(404, ['error' => 'No snapshot available']);

    header('Content-Type: application/x-sqlite3');
    header('Content-Disposition: attachment; filename="learninghub.sqlite"');
    header('X-SHA256: ' . $row['sha256']);
    header('X-Size-Bytes: ' . $row['size_bytes']);
    echo $row['sqlite_blob'];
    exit;
}

// POST /push_uploads (ZIP)
if ($method === 'POST' && $path === '/push_uploads') {
    require_auth();

    if (!isset($_FILES['zip']) || !is_uploaded_file($_FILES['zip']['tmp_name'])) {
        json_out(400, ['error' => 'Missing file field "zip"']);
    }

    $bytes = file_get_contents($_FILES['zip']['tmp_name']);
    if ($bytes === false) json_out(500, ['error' => 'Failed to read uploaded zip']);

    $size = strlen($bytes);
    $sha  = hash('sha256', $bytes);

    $pdo = get_pdo();
    ensure_uploads_table($pdo);

    $stmt = $pdo->prepare("INSERT INTO uploads_snapshots (sha256, size_bytes, zip_blob) VALUES (?, ?, ?)");
    $stmt->execute([$sha, $size, $bytes]);

    // NEW: write meta
    ensure_meta_table($pdo);
    $device = trim((string)($_POST['device_name'] ?? 'Unknown'));
    $pushed = trim((string)($_POST['pushed_at'] ?? date('c')));

    $stmt2 = $pdo->prepare("
        UPDATE sync_meta
        SET last_uploads_pushed_by = ?,
            last_uploads_pushed_at = ?,
            last_uploads_sha256 = ?,
            last_uploads_size_bytes = ?
        WHERE id = 1
    ");
    $stmt2->execute([$device, $pushed, $sha, $size]);

    json_out(200, [
        'status' => 'ok',
        'sha256' => $sha,
        'size_bytes' => $size,
        'last_uploads' => ['by' => $device, 'at' => $pushed]
    ]);
}

// GET /pull_uploads (ZIP)
if ($method === 'GET' && $path === '/pull_uploads') {
    require_auth();

    $pdo = get_pdo();
    ensure_uploads_table($pdo);

    $stmt = $pdo->query("SELECT sha256, size_bytes, zip_blob FROM uploads_snapshots ORDER BY id DESC LIMIT 1");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) json_out(404, ['error' => 'No uploads snapshot available']);

    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="uploads.zip"');
    header('X-SHA256: ' . $row['sha256']);
    header('X-Size-Bytes: ' . $row['size_bytes']);
    echo $row['zip_blob'];
    exit;
}

// Fallback
json_out(404, ['error' => 'Not Found', 'path' => $path, 'method' => $method]);
