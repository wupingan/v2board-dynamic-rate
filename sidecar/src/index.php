<?php

declare(strict_types=1);

use DynamicRate\Lib\EnvLoader;

require_once dirname(__DIR__, 2) . '/worker/lib/EnvLoader.php';

session_name('dynamic_rate_sid');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, X-Access-Token');
header('Access-Control-Allow-Methods: GET,POST,DELETE,OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$env = EnvLoader::fromFile(dirname(__DIR__) . '/.env');
$projectRoot = $env['PROJECT_ROOT'] ?? dirname(__DIR__, 3);
$sideToken = $env['SIDE_ACCESS_TOKEN'] ?? '';
$adminEntry = '/' . ltrim((string)($env['ADMIN_ENTRY'] ?? 'admin-' . substr(sha1((string)$sideToken), 0, 16)), '/');
$adminUser = (string)($env['ADMIN_USERNAME'] ?? 'admin');
$adminPassword = (string)($env['ADMIN_PASSWORD'] ?? '');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

if ($method === 'GET' && $path === '/health') {
    respond(['ok' => true]);
}

if ($method === 'GET' && $path === $adminEntry) {
    $adminFile = isUiAuthed() ? __DIR__ . '/admin.html' : __DIR__ . '/login.html';
    if (!is_file($adminFile)) {
        respond(['error' => 'admin page not found'], 404);
    }
    header('Content-Type: text/html; charset=utf-8');
    $content = file_get_contents($adminFile);
    if (!is_string($content)) {
        respond(['error' => 'admin page read failed'], 500);
    }
    $content = str_replace('__ADMIN_ENTRY__', $adminEntry, $content);
    echo $content;
    exit;
}

if ($method === 'GET' && $path === '/') {
    header('Location: ' . $adminEntry, true, 302);
    exit;
}

if ($method === 'POST' && $path === '/ui/login') {
    $body = getJsonBody();
    $username = (string)($body['username'] ?? '');
    $password = (string)($body['password'] ?? '');
    if ($adminPassword === '') {
        respond(['error' => 'ADMIN_PASSWORD is empty'], 500);
    }
    if (!hash_equals($adminUser, $username) || !hash_equals($adminPassword, $password)) {
        respond(['error' => '用户名或密码错误'], 401);
    }
    $_SESSION['dr_admin_auth'] = 1;
    session_regenerate_id(true);
    respond(['data' => true]);
}

if ($method === 'POST' && $path === '/ui/logout') {
    $_SESSION = [];
    if (session_id() !== '') {
        session_destroy();
    }
    respond(['data' => true]);
}

if ($method === 'GET' && $path === '/ui/session') {
    respond(['data' => ['authed' => isUiAuthed()]]);
}

if (!isUiAuthed()) {
    $reqToken = $_SERVER['HTTP_X_ACCESS_TOKEN'] ?? '';
    if ($sideToken === '' || !hash_equals($sideToken, $reqToken)) {
        respond(['error' => 'unauthorized'], 401);
    }
}

try {
    $pdo = buildPdo($projectRoot);

    if ($method === 'GET' && $path === '/api/nodes') {
        $type = (string)($_GET['server_type'] ?? '');
        $allowed = tableMap();
        $result = [];

        if ($type !== '') {
            if (!isset($allowed[$type])) {
                respond(['error' => 'invalid server_type'], 422);
            }
            $result = fetchNodesByType($pdo, $type, $allowed[$type]);
            respond(['data' => $result]);
        }

        foreach ($allowed as $serverType => $table) {
            $result = array_merge($result, fetchNodesByType($pdo, $serverType, $table));
        }
        respond(['data' => $result]);
    }

    if ($method === 'GET' && $path === '/api/rules/list') {
        $serverType = (string)($_GET['server_type'] ?? '');
        if ($serverType !== '') {
            $stmt = $pdo->prepare('SELECT * FROM v2_dynamic_rate_rule WHERE server_type=:t ORDER BY id DESC');
            $stmt->execute([':t' => $serverType]);
            $rows = $stmt->fetchAll();
        } else {
            $rows = $pdo->query('SELECT * FROM v2_dynamic_rate_rule ORDER BY id DESC')->fetchAll();
        }
        foreach ($rows as &$row) {
            $row['rules_json'] = safeDecodeRules($row['rules_json'] ?? null);
        }
        unset($row);
        respond(['data' => $rows]);
    }

    if ($method === 'GET' && $path === '/api/rules') {
        $serverType = (string)($_GET['server_type'] ?? '');
        $serverId = (int)($_GET['server_id'] ?? 0);
        if ($serverType === '' || $serverId <= 0) {
            respond(['error' => 'invalid params'], 422);
        }

        $stmt = $pdo->prepare('SELECT * FROM v2_dynamic_rate_rule WHERE server_type=:t AND server_id=:i LIMIT 1');
        $stmt->execute([':t' => $serverType, ':i' => $serverId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $row['rules_json'] = safeDecodeRules($row['rules_json'] ?? null);
        }
        respond(['data' => $row ?: null]);
    }

    if ($method === 'POST' && $path === '/api/rules/batch') {
        $body = getJsonBody();
        $items = $body['items'] ?? null;
        if (!is_array($items)) {
            respond(['error' => 'items is required'], 422);
        }

        $result = [];
        $stmt = $pdo->prepare('SELECT * FROM v2_dynamic_rate_rule WHERE server_type=:t AND server_id=:i LIMIT 1');
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $t = (string)($item['server_type'] ?? '');
            $i = (int)($item['server_id'] ?? 0);
            if ($t === '' || $i <= 0) {
                continue;
            }
            $stmt->execute([':t' => $t, ':i' => $i]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $row['rules_json'] = safeDecodeRules($row['rules_json'] ?? null);
                $result[] = $row;
            }
        }

        respond(['data' => $result]);
    }

    if ($method === 'POST' && $path === '/api/rules/upsert') {
        $body = getJsonBody();

        $serverType = (string)($body['server_type'] ?? '');
        $serverId = (int)($body['server_id'] ?? 0);
        $enabled = ((int)($body['enabled'] ?? 0)) === 1 ? 1 : 0;
        $baseRate = (float)($body['base_rate'] ?? 0);
        $timezone = (string)($body['timezone'] ?? 'Asia/Shanghai');
        $rules = $body['rules_json'] ?? [];

        if (!in_array($serverType, ['vmess', 'trojan', 'shadowsocks', 'vless', 'tuic', 'hysteria', 'anytls', 'v2node'], true)) {
            respond(['error' => 'invalid server_type'], 422);
        }
        if ($serverId <= 0 || $baseRate <= 0) {
            respond(['error' => 'invalid server_id/base_rate'], 422);
        }
        if (!is_array($rules)) {
            respond(['error' => 'rules_json must be array'], 422);
        }

        $rules = normalizeRules($rules);
        $now = time();
        $rulesJson = json_encode($rules, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $sql = 'INSERT INTO v2_dynamic_rate_rule(server_type,server_id,enabled,base_rate,timezone,rules_json,last_applied_rate,updated_at,created_at)
                VALUES(:t,:i,:e,:b,:z,:r,NULL,:u,:c)
                ON DUPLICATE KEY UPDATE enabled=VALUES(enabled),base_rate=VALUES(base_rate),timezone=VALUES(timezone),rules_json=VALUES(rules_json),updated_at=VALUES(updated_at)';
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':t' => $serverType,
            ':i' => $serverId,
            ':e' => $enabled,
            ':b' => number_format($baseRate, 2, '.', ''),
            ':z' => $timezone,
            ':r' => $rulesJson,
            ':u' => $now,
            ':c' => $now,
        ]);

        respond(['data' => true]);
    }

    if ($method === 'DELETE' && preg_match('#^/api/rules/(\d+)$#', $path, $m)) {
        $id = (int)$m[1];
        $stmt = $pdo->prepare('DELETE FROM v2_dynamic_rate_rule WHERE id=:id');
        $stmt->execute([':id' => $id]);
        respond(['data' => true]);
    }

    respond(['error' => 'not found'], 404);
} catch (Throwable $e) {
    respond(['error' => $e->getMessage()], 500);
}

function buildPdo(string $projectRoot): PDO
{
    $env = EnvLoader::fromFile(rtrim($projectRoot, '/') . '/.env');
    $dbHost = $env['DB_HOST'] ?? '127.0.0.1';
    $dbPort = $env['DB_PORT'] ?? '3306';
    $dbName = $env['DB_DATABASE'] ?? '';
    $dbUser = $env['DB_USERNAME'] ?? '';
    $dbPass = $env['DB_PASSWORD'] ?? '';

    if ($dbName === '' || $dbUser === '') {
        throw new RuntimeException('db config missing');
    }

    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $dbHost, $dbPort, $dbName);
    return new PDO($dsn, $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
}

function getJsonBody(): array
{
    $raw = file_get_contents('php://input');
    if (!is_string($raw) || $raw === '') {
        return [];
    }
    $json = json_decode($raw, true);
    return is_array($json) ? $json : [];
}

function isUiAuthed(): bool
{
    return isset($_SESSION['dr_admin_auth']) && (int)$_SESSION['dr_admin_auth'] === 1;
}

function tableMap(): array
{
    return [
        'vmess' => 'v2_server_vmess',
        'trojan' => 'v2_server_trojan',
        'shadowsocks' => 'v2_server_shadowsocks',
        'vless' => 'v2_server_vless',
        'tuic' => 'v2_server_tuic',
        'hysteria' => 'v2_server_hysteria',
        'anytls' => 'v2_server_anytls',
        'v2node' => 'v2_server_v2node',
    ];
}

function fetchNodesByType(PDO $pdo, string $serverType, string $table): array
{
    $existsStmt = $pdo->prepare('SELECT COUNT(*) AS c FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :t');
    $existsStmt->execute([':t' => $table]);
    $exists = (int)($existsStmt->fetch()['c'] ?? 0) > 0;
    if (!$exists) {
        return [];
    }

    $sql = "SELECT id, name, rate FROM {$table} ORDER BY id DESC";
    $rows = $pdo->query($sql)->fetchAll();
    foreach ($rows as &$row) {
        $row['server_type'] = $serverType;
        $row['server_id'] = (int)$row['id'];
    }
    unset($row);
    return $rows;
}

function normalizeRules(array $rules): array
{
    $result = [];
    foreach ($rules as $rule) {
        if (!is_array($rule)) {
            continue;
        }
        $days = $rule['days'] ?? null;
        $start = $rule['start'] ?? null;
        $end = $rule['end'] ?? null;
        $rate = $rule['rate'] ?? null;

        if (!is_array($days) || !is_string($start) || !is_string($end) || !is_numeric($rate)) {
            continue;
        }
        if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $start) || !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $end)) {
            continue;
        }

        $days = array_values(array_unique(array_filter(array_map('intval', $days), static function ($d) {
            return $d >= 0 && $d <= 6;
        })));
        $r = (float)$rate;
        if (empty($days) || $r <= 0) {
            continue;
        }

        $result[] = [
            'days' => $days,
            'start' => $start,
            'end' => $end,
            'rate' => round($r, 2),
        ];
    }
    return $result;
}

function safeDecodeRules($rules): array
{
    if (!is_string($rules) || $rules === '') {
        return [];
    }
    $arr = json_decode($rules, true);
    return is_array($arr) ? $arr : [];
}

function respond(array $data, int $status = 200): void
{
    header('Content-Type: application/json; charset=utf-8');
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
