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

$rows = $pdo->query('SELECT id, rules_json FROM v2_dynamic_rate_rule')->fetchAll();
$updated = 0;
$skipped = 0;

$updateStmt = $pdo->prepare('UPDATE v2_dynamic_rate_rule SET rules_json=:r, updated_at=:u WHERE id=:id');

foreach ($rows as $row) {
    $id = (int)$row['id'];
    $rulesRaw = $row['rules_json'] ?? null;

    if (!is_string($rulesRaw) || trim($rulesRaw) === '') {
        $skipped++;
        continue;
    }

    $decoded = json_decode($rulesRaw, true);
    if (!is_array($decoded)) {
        $skipped++;
        continue;
    }

    $normalized = [];
    foreach ($decoded as $rule) {
        if (!is_array($rule)) {
            continue;
        }
        $days = $rule['days'] ?? [];
        $start = $rule['start'] ?? '00:00';
        $end = $rule['end'] ?? '23:59';
        $rate = $rule['rate'] ?? 1;

        if (!is_array($days)) {
            $days = [];
        }

        $normalized[] = [
            'days' => array_values(array_unique(array_filter(array_map('intval', $days), static fn($d) => $d >= 0 && $d <= 6))),
            'start' => is_string($start) ? $start : '00:00',
            'end' => is_string($end) ? $end : '23:59',
            'rate' => round((float)$rate, 2),
        ];
    }

    $newJson = json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($newJson)) {
        $skipped++;
        continue;
    }

    if ($newJson === $rulesRaw) {
        $skipped++;
        continue;
    }

    $updateStmt->execute([
        ':r' => $newJson,
        ':u' => time(),
        ':id' => $id,
    ]);
    $updated++;
}

fwrite(STDOUT, "[dynamic-rate] normalize rules done, updated={$updated}, skipped={$skipped}\n");
