#!/usr/bin/env php
<?php

declare(strict_types=1);

use DynamicRate\Lib\EnvLoader;
use DynamicRate\Lib\RateCalculator;

require_once __DIR__ . '/lib/EnvLoader.php';
require_once __DIR__ . '/lib/RateCalculator.php';

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

$lockName = 'xiao_dynamic_rate_apply_lock';
$lockStmt = $pdo->query("SELECT GET_LOCK('{$lockName}', 0) AS l");
$locked = (int)($lockStmt->fetch()['l'] ?? 0) === 1;
if (!$locked) {
    fwrite(STDOUT, "[dynamic-rate] another worker is running, skip\n");
    exit(0);
}

try {
    $rules = $pdo->query('SELECT * FROM v2_dynamic_rate_rule')->fetchAll();
    $processed = 0;
    $changed = 0;
    $skipped = 0;

    foreach ($rules as $row) {
        $type = (string)$row['server_type'];
        $serverId = (int)$row['server_id'];
        $enabled = ((int)$row['enabled']) === 1;
        $baseRate = (float)$row['base_rate'];
        $timezone = (string)($row['timezone'] ?: 'Asia/Shanghai');
        $rulesJson = $row['rules_json'] ?? null;

        if (!isset($tableMap[$type])) {
            $skipped++;
            continue;
        }

        $table = $tableMap[$type];
        if (!isset($existingTables[$table])) {
            $skipped++;
            continue;
        }
        $effectiveRate = RateCalculator::resolve($baseRate, $enabled, is_string($rulesJson) ? $rulesJson : null, $timezone);
        $effectiveRateString = number_format($effectiveRate, 2, '.', '');
        $lastApplied = isset($row['last_applied_rate']) ? (string)$row['last_applied_rate'] : null;

        if ($lastApplied !== $effectiveRateString) {
            $stmt = $pdo->prepare("UPDATE {$table} SET rate = :rate WHERE id = :id");
            $stmt->execute([
                ':rate' => $effectiveRateString,
                ':id' => $serverId,
            ]);
            $changed++;
        }

        $upd = $pdo->prepare('UPDATE v2_dynamic_rate_rule SET last_applied_rate = :r, updated_at = :u WHERE id = :id');
        $upd->execute([
            ':r' => $effectiveRateString,
            ':u' => time(),
            ':id' => (int)$row['id'],
        ]);

        $processed++;
    }

    fwrite(STDOUT, "[dynamic-rate] done, processed={$processed}, changed={$changed}, skipped={$skipped}\n");
} finally {
    $pdo->query("SELECT RELEASE_LOCK('{$lockName}')");
}
