<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require_once __DIR__ . '/ranking-parameters.php';
require_once __DIR__ . '/ranking-snapshot.php';
require_once __DIR__ . '/ticker-settings-lib.php';

function respondCachedSymbols(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    $martingaleRiskLimits = rankingMartingaleRiskLimits($_GET);
    $simulationCosts = rankingSimulationCosts($_GET);
    $root = dirname(__DIR__);
    $config = json_decode((string) file_get_contents($root . '/config.json'), true, 512, JSON_THROW_ON_ERROR);
    $entryProfiles = json_decode((string) file_get_contents($root . '/entry_profiles.json'), true, 512, JSON_THROW_ON_ERROR);
    $exitProfiles = json_decode((string) file_get_contents($root . '/exit_profiles.json'), true, 512, JSON_THROW_ON_ERROR);
    $ranking = rankingParameters($_GET, $entryProfiles, $exitProfiles)['worker'];
    $timezone = new DateTimeZone('Europe/Kyiv');
    $today = new DateTimeImmutable('today', $timezone);
    $startValue = (string) ($_GET['start_day'] ?? '2026-10-06');
    $endValue = (string) ($_GET['end_day'] ?? $today->format('Y-m-d'));
    $startDay = DateTimeImmutable::createFromFormat('!Y-m-d', $startValue, $timezone);
    $endDay = DateTimeImmutable::createFromFormat('!Y-m-d', $endValue, $timezone);
    $startErrors = DateTimeImmutable::getLastErrors();
    $endErrors = DateTimeImmutable::getLastErrors();
    if ($startDay === false || $endDay === false
        || ($startErrors !== false && ($startErrors['warning_count'] || $startErrors['error_count']))
        || ($endErrors !== false && ($endErrors['warning_count'] || $endErrors['error_count']))
        || $startDay->format('Y-m-d') !== $startValue || $endDay->format('Y-m-d') !== $endValue
        || $endDay < $startDay) {
        respondCachedSymbols(['status' => 'error', 'message' => 'Проверьте выбранный диапазон дат.'], 400);
    }
    $balance = (string) ($_GET['balance'] ?? '100');
    $fee = (string) ($_GET['fee'] ?? '0.06');
    if (!preg_match('/^\d{1,12}(?:\.\d{1,12})?$/', $balance) || (float) $balance <= 0 || (float) $balance > 1000000000
        || !preg_match('/^\d{1,12}(?:\.\d{1,12})?$/', $fee) || (float) $fee < 0 || (float) $fee > 5) {
        respondCachedSymbols(['status' => 'error', 'message' => 'Некорректные параметры расчёта.'], 400);
    }
    $martingaleMode = (string) ($_GET['martingale_mode'] ?? 'none');
    $martingaleTiming = (string) ($_GET['martingale_timing'] ?? 'immediate');
    $martingaleAttempts = (int) ($_GET['martingale_attempts'] ?? 3);
    if (!in_array($martingaleMode, ['none', 'simple', 'reverse', 'simple_profit', 'reverse_profit'], true)
        || !in_array($martingaleTiming, ['immediate', 'rules'], true)
        || $martingaleAttempts < 2 || $martingaleAttempts > 10) {
        respondCachedSymbols(['status' => 'error', 'message' => 'Некорректные параметры мартингейла.'], 400);
    }
    $startUtc = $startDay->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.v');
    $endUtc = $endDay->modify('+1 day')->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.v');
    $db = $config['mysql'];
    $password = getenv($db['password_env']) ?: '';
    if ($password === '') respondCachedSymbols(['status' => 'error', 'message' => 'Не задан пароль подключения к MySQL.'], 503);
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $db['host'], $db['port'], $db['database']),
        $db['user'], $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );

    $cacheDir = (getenv('HOME') ?: '/home/alex') . '/.ai/home/.local/bitget-backtest-cache';
    if (!is_dir($cacheDir)) respondCachedSymbols(['status' => 'ready', 'symbols' => []]);
    $generationPath = $cacheDir . '/strategy-ranking-generation.txt';
    $generation = is_file($generationPath) ? trim((string) file_get_contents($generationPath)) : 'initial';
    if ($generation === '') $generation = 'initial';
    $requestedGeneration = (string) ($_GET['ranking_generation'] ?? '');
    if ($requestedGeneration === '' || !hash_equals($generation, $requestedGeneration)) {
        respondCachedSymbols(['status' => 'cancelled', 'symbols' => []]);
    }
    $snapshotIds = rankingSnapshotIds(
        $pdo, $cacheDir, $config['category'], $startValue, $endValue, $startUtc, $endUtc, $generation,
    );
    $snapshotIds = filterEnabledTickerSnapshotIds($snapshotIds, $config, trim((string) ($_GET['ticker_set'] ?? '')));
    $saveMode = (string) ($_GET['save_mode'] ?? '1') !== '0';
    $minTrades = $saveMode ? 3 : 1;
    $symbols = [];
    foreach ($snapshotIds as $symbol => $_maxId) {
        $keyParts = [
            'ranking-v31-range-reset-at-start', $generation, $config['category'], $symbol,
            $startValue, $endValue, $balance, $fee, $minTrades, $ranking,
            $martingaleMode, $martingaleTiming, $martingaleAttempts,
        ];
        if ($martingaleMode !== 'none') {
            array_push($keyParts, ...rankingMartingaleCacheIdentity($martingaleMode, $martingaleRiskLimits));
        }
        array_push($keyParts, ...rankingSimulationCostsIdentity($simulationCosts));
        array_push($keyParts, ...rankingContractRulesIdentity($config['category'], [$symbol]));
        $snapshotRevision = rankingSnapshotRevision($cacheDir, $config['category'], $startValue, $endValue, $generation);
        if ($snapshotRevision !== null) $keyParts[] = ['refreshed_snapshot' => $snapshotRevision];
        $key = hash('sha256', json_encode($keyParts, JSON_THROW_ON_ERROR));
        $resultPath = $cacheDir . '/' . $key . '.json';
        if (!is_file($resultPath)) continue;
        $result = json_decode((string) file_get_contents($resultPath), true);
        if (!is_array($result) || ($result['status'] ?? '') !== 'ready' || !is_array($result['entries'] ?? null)) continue;
        $best = null;
        foreach ($result['entries'] as $entry) {
            if (!is_array($entry) || !is_numeric($entry['best_pnl'] ?? null)) continue;
            if ($best === null || (float) $entry['best_pnl'] > (float) $best['best_pnl']) $best = $entry;
        }
        if ($best !== null) {
            $symbols[] = [
                'symbol' => (string) $symbol,
                'best_pnl' => (float) $best['best_pnl'],
                'entry_label' => (string) ($best['label'] ?? ''),
            ];
        }
    }
    usort($symbols, static fn (array $left, array $right): int =>
        ($right['best_pnl'] <=> $left['best_pnl']) ?: strcmp($left['symbol'], $right['symbol'])
    );
    respondCachedSymbols(['status' => 'ready', 'symbols' => $symbols]);
} catch (Throwable $error) {
    respondCachedSymbols(['status' => 'error', 'message' => 'Не удалось прочитать сохранённые результаты тикеров.'], 503);
}
