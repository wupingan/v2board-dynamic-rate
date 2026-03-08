#!/usr/bin/env php
<?php

declare(strict_types=1);

use DynamicRate\Lib\EnvLoader;

require_once __DIR__ . '/lib/EnvLoader.php';

$projectRoot = $argv[1] ?? dirname(__DIR__, 2);
$envPath = rtrim($projectRoot, '/') . '/.env';
$env = EnvLoader::fromFile($envPath);

$dbHost = $env['DB_HOST'] ?? '127.0.0.1';
$dbPort = $env['DB_PORT'] ?? '3306';
$dbName = $env['DB_DATABASE'] ?? '';
$dbUser = $env['DB_USERNAME'] ?? '';
$dbPass = $env['DB_PASSWORD'] ?? '';

if ($dbName === '' || $dbUser === '') {
    fwrite(STDERR, "[dynamic-rate] DB config missing in .env\n");
    exit(1);
}

$dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $dbHost, $dbPort, $dbName);
$pdo = new PDO($dsn, $dbUser, $dbPass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$tableMap = [
    'vmess' => 'v2_server_vmess',
    'trojan' => 'v2_server_trojan',
    'shadowsocks' => 'v2_server_shadowsocks',
    'vless' => 'v2_server_vless',
    'tuic' => 'v2_server_tuic',
    'hysteria' => 'v2_server_hysteria',
    'anytls' => 'v2_server_anytls',
    'v2node' => 'v2_server_v2node',
];

$existingTables = [];
$tableRows = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
foreach ($tableRows as $t) {
    $existingTables[(string)$t] = true;
}

$existsRuleTable = isset($existingTables['v2_dynamic_rate_rule']);
if (!$existsRuleTable) {
    fwrite(STDOUT, "[dynamic-rate] rule table not found, skip restore\n");
    exit(0);
}

$rows = $pdo->query('SELECT server_type, server_id, base_rate FROM v2_dynamic_rate_rule')->fetchAll();
$restored = 0;
$skipped = 0;

foreach ($rows as $row) {
    $type = (string)($row['server_type'] ?? '');
    $serverId = (int)($row['server_id'] ?? 0);
    $baseRate = (float)($row['base_rate'] ?? 1);

    if ($type === '' || $serverId <= 0 || !isset($tableMap[$type])) {
        $skipped++;
        continue;
    }

    $table = $tableMap[$type];
    if (!isset($existingTables[$table])) {
        $skipped++;
        continue;
    }

    $stmt = $pdo->prepare("UPDATE {$table} SET rate = :rate WHERE id = :id");
    $stmt->execute([
        ':rate' => number_format($baseRate, 2, '.', ''),
        ':id' => $serverId,
    ]);
    $restored++;
}

fwrite(STDOUT, "[dynamic-rate] restore base rate done, restored={$restored}, skipped={$skipped}\n");
