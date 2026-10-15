<?php
declare(strict_types=1);
require_once __DIR__ . '/strategy-return-run-lib.php';

function writeReturnSummaryJson(string $path, array $payload): void
{
    $temporary = tempnam(dirname($path), '.return-summary-');
    if ($temporary === false) throw new RuntimeException('Unable to prepare return summary.');
    try {
        if (file_put_contents($temporary, json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX) === false
            || !rename($temporary, $path)) throw new RuntimeException('Unable to save return summary.');
        chmod($path, 0600);
    } finally {
        if (is_file($temporary)) unlink($temporary);
    }
}

function returnSummaryPath(array $job, string $symbol): string
{
    $cacheDir = (getenv('HOME') ?: '/home/alex') . '/.ai/home/.local/bitget-backtest-cache';
    $directory = $cacheDir . '/strategy-totals';
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Unable to prepare strategy totals directory.');
    }
    $config = json_decode((string) file_get_contents(dirname(__DIR__) . '/config.json'), true, 512, JSON_THROW_ON_ERROR);
    $generationPath = $cacheDir . '/strategy-ranking-generation.txt';
    $generation = is_file($generationPath) ? trim((string) file_get_contents($generationPath)) : '0';
    $identity = [
        'strategy-totals-v3-contained-trades', $config['category'], $generation,
        (string) ($job['run_key'] ?? ''), $symbol, $job['start_day'], $job['end_day'],
        $job['exit_confirmation_mode'], (int) $job['exit_confirmation_snapshots'],
    ];
    return $directory . '/' . hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)) . '.sqlite';
}

function returnSummaryDatabase(PDO $source, array $job, string $symbol): PDO
{
    $database = strategyReturnRunDatabase((string) ($job['run_key'] ?? ''), $symbol);
    if (!$database) throw new RuntimeException('Доходность этого запуска нужно пересчитать; старые смешанные точки не используются.');
    $status = strategyReturnRunStatus((string) ($job['run_key'] ?? ''));
    if (!$status || $status['status'] !== 'ready' || $status['start_day'] !== $job['start_day'] || $status['end_day'] !== $job['end_day']) {
        throw new RuntimeException('Для выбранного диапазона нужен завершённый отдельный расчёт.');
    }
    return $database;
}

function returnSummaryRows(PDO $source, array $job, string $symbol): Generator
{
    $store = returnSummaryDatabase($source, $job, $symbol);
    $query = $store->query('SELECT entry_id,entry_label,exit_id,exit_label,cumulative_pnl,closed_trade_count FROM totals');
    while (($row = $query->fetch()) !== false) yield ['symbol' => $symbol] + $row;
}
