<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function respondComparison(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function comparisonCanonical(mixed $value): mixed
{
    if (!is_array($value)) return $value;
    if (!array_is_list($value)) ksort($value, SORT_STRING);
    foreach ($value as $key => $item) $value[$key] = comparisonCanonical($item);
    return $value;
}

function comparisonScenario(array $metadata): array
{
    $ranking = $metadata['parameters']['ranking'] ?? [];
    $entryMode = (string) ($ranking['entry_signal_mode'] ?? 'exact');
    $direction = (string) ($ranking['entry_direction_mode'] ?? 'follow');
    $exitMode = (string) ($ranking['exit_confirmation_mode'] ?? 'immediate');
    $snapshots = max(1, (int) ($ranking['exit_confirmation_snapshots'] ?? 60));
    $entryLabel = $entryMode === 'within' ? 'Вход внутри окна' : 'Вход за точное число снимков';
    $directionLabel = $direction === 'reverse' ? 'Против движения' : 'По движению';
    $exitLabels = [
        'immediate' => 'TP/SL сразу',
        'both_delayed' => "TP/SL после {$snapshots} интервалов",
        'tp_delayed' => "TP после {$snapshots} интервалов, SL сразу",
        'sl_delayed' => "TP сразу, SL после {$snapshots} интервалов",
        'none' => 'Без выхода',
    ];
    return [
        'key' => implode('|', [$entryMode, $direction, $exitMode, $exitMode === 'immediate' || $exitMode === 'none' ? 0 : $snapshots]),
        'label' => $entryLabel . ' · ' . $directionLabel . ' · ' . ($exitLabels[$exitMode] ?? $exitMode),
        'entry_mode' => $entryMode,
        'direction' => $direction,
        'exit_mode' => $exitMode,
        'snapshots' => $snapshots,
    ];
}

try {
    $currentKey = (string) ($_GET['cache_key'] ?? '');
    if (preg_match('/^[a-f0-9]{64}$/', $currentKey) !== 1) {
        respondComparison(['status' => 'error', 'message' => 'Некорректный ключ рейтинга.'], 400);
    }
    $cacheDir = (getenv('HOME') ?: '/home/alex') . '/.ai/home/.local/bitget-backtest-cache';
    $currentMetadataPath = $cacheDir . '/portfolio-' . $currentKey . '.meta.json';
    $currentResultPath = $cacheDir . '/portfolio-' . $currentKey . '.json';
    $currentStatusPath = $cacheDir . '/portfolio-' . $currentKey . '.status.json';
    if (!is_file($currentMetadataPath) || !is_file($currentResultPath)) respondComparison(['status' => 'missing']);
    $currentMetadata = json_decode((string) file_get_contents($currentMetadataPath), true);
    if (!is_array($currentMetadata)) respondComparison(['status' => 'missing']);

    $baseParameters = $currentMetadata['parameters'] ?? [];
    unset($baseParameters['min_trades']);
    $baseRanking = $baseParameters['ranking'] ?? [];
    unset($baseRanking['entry_signal_mode'], $baseRanking['entry_direction_mode'], $baseRanking['exit_confirmation_mode'], $baseRanking['exit_confirmation_snapshots']);
    if (isset($baseRanking['exit_profiles']) && is_array($baseRanking['exit_profiles'])) unset($baseRanking['exit_profiles']['no_exit']);
    $baseParameters['ranking'] = $baseRanking;
    $expectedBase = [
        'ticker_set_id' => $currentMetadata['ticker_set']['id'] ?? null,
        'symbols' => $currentMetadata['ticker_set']['symbols'] ?? null,
        'start_day' => $currentMetadata['start_day'] ?? null,
        'end_day' => $currentMetadata['end_day'] ?? null,
        'parameters' => $baseParameters,
    ];

    $runsByScenario = [];
    foreach (glob($cacheDir . '/portfolio-*.meta.json') ?: [] as $metadataPath) {
        $metadata = json_decode((string) @file_get_contents($metadataPath), true);
        if (!is_array($metadata) || !is_array($metadata['parameters'] ?? null)) continue;
        $ranking = $metadata['parameters']['ranking'] ?? [];
        if (!is_array($ranking)) continue;
        $candidateRanking = $ranking;
        unset($candidateRanking['entry_signal_mode'], $candidateRanking['entry_direction_mode'], $candidateRanking['exit_confirmation_mode'], $candidateRanking['exit_confirmation_snapshots']);
        if (isset($candidateRanking['exit_profiles']) && is_array($candidateRanking['exit_profiles'])) unset($candidateRanking['exit_profiles']['no_exit']);
        $candidateParameters = $metadata['parameters'];
        unset($candidateParameters['min_trades']);
        $candidateParameters['ranking'] = $candidateRanking;
        $candidateBase = [
            'ticker_set_id' => $metadata['ticker_set']['id'] ?? null,
            'symbols' => $metadata['ticker_set']['symbols'] ?? null,
            'start_day' => $metadata['start_day'] ?? null,
            'end_day' => $metadata['end_day'] ?? null,
            'parameters' => $candidateParameters,
        ];
        if (comparisonCanonical($candidateBase) !== comparisonCanonical($expectedBase)) continue;

        $key = (string) ($metadata['cache_key'] ?? '');
        if (preg_match('/^[a-f0-9]{64}$/', $key) !== 1) continue;
        $resultPath = $cacheDir . '/portfolio-' . $key . '.json';
        $statusPath = $cacheDir . '/portfolio-' . $key . '.status.json';
        if (!is_file($resultPath)) continue;
        $result = json_decode((string) @file_get_contents($resultPath), true);
        $status = is_file($statusPath) ? json_decode((string) @file_get_contents($statusPath), true) : null;
        if (!is_array($result) || ($result['status'] ?? '') !== 'ready'
            || ($result['ranking_data_source'] ?? '') !== 'strategy_return_points'
            || (is_array($status) && ($status['status'] ?? '') !== 'ready')) continue;

        $scenario = comparisonScenario($metadata);
        if ($scenario['exit_mode'] === 'none') {
            $hasNoExitResults = false;
            foreach (($result['ticker_leaders'] ?? []) as $leader) {
                if (($leader['exit_id'] ?? null) === 'none') { $hasNoExitResults = true; break; }
            }
            if (!$hasNoExitResults && ($result['ticker_leaders'] ?? []) !== []) continue;
        }
        $mtime = (int) @filemtime($resultPath);
        if (!isset($runsByScenario[$scenario['key']]) || $mtime > $runsByScenario[$scenario['key']]['cached_at']) {
            $runsByScenario[$scenario['key']] = [
                'cache_key' => $key,
                'cached_at' => $mtime,
                'scenario' => $scenario,
                'ticker_leaders' => array_values(is_array($result['ticker_leaders'] ?? null) ? $result['ticker_leaders'] : []),
            ];
        }
    }

    if (count($runsByScenario) < 2) respondComparison(['status' => 'missing', 'scenario_count' => count($runsByScenario)]);
    $runs = array_values($runsByScenario);
    usort($runs, static fn(array $left, array $right): int => strcmp($left['scenario']['label'], $right['scenario']['label']));
    foreach ($runs as &$run) unset($run['cached_at']);
    unset($run);
    respondComparison([
        'status' => 'ready',
        'start_day' => (string) ($currentMetadata['start_day'] ?? ''),
        'end_day' => (string) ($currentMetadata['end_day'] ?? ''),
        'ticker_count' => count($currentMetadata['ticker_set']['symbols'] ?? []),
        'runs' => $runs,
    ]);
} catch (Throwable $error) {
    respondComparison(['status' => 'error', 'message' => 'Не удалось загрузить сохранённые рейтинги для сравнения.'], 503);
}
