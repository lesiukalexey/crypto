<?php
declare(strict_types=1);

function strategyReturnRunDatabase(string $runKey, string $symbol): ?PDO
{
    if (!preg_match('/^[a-f0-9]{64}$/', $runKey) || !preg_match('/^[A-Z0-9]{2,40}$/', $symbol)) return null;
    $path = (getenv('HOME') ?: '/home/alex') . '/.ai/home/.local/bitget-backtest-cache/return-runs/' . $runKey . '/' . $symbol . '.sqlite';
    if (!is_file($path)) return null;
    $pdo = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $pdo->exec('PRAGMA query_only=ON');
    try {
        $context=json_decode((string)$pdo->query("SELECT value FROM metadata WHERE name='context'")->fetchColumn(),true);
        if (!is_array($context)) return null;
    } catch (PDOException $error) { return null; }
    return $pdo;
}

function strategyReturnRunStatus(string $runKey): ?array
{
    if (!preg_match('/^[a-f0-9]{64}$/', $runKey)) return null;
    $directory = (getenv('HOME') ?: '/home/alex') . '/.ai/home/.local/bitget-backtest-cache';
    foreach (glob($directory . '/*.status.json') ?: [] as $path) {
        $status = json_decode((string) @file_get_contents($path), true);
        if (is_array($status) && ($status['return_run_key'] ?? '') === $runKey) return $status;
    }
    return null;
}
