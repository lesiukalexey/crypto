<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

try {
    $home = getenv('HOME') ?: '/home/alex';
    $cacheDir = $home . '/.ai/home/.local/bitget-backtest-cache';
    if (!is_dir($cacheDir)) {
        echo json_encode(['active' => [], 'waiting' => [], 'updated_at' => time()]);
        exit;
    }

    $workerTypes = [
        'strategy_ranking.py' => ['type' => 'strategy', 'prefix' => ''],
        'symbol_ranking.py' => ['type' => 'symbols', 'prefix' => 'symbols-'],
        'portfolio_ranking.py' => ['type' => 'portfolio', 'prefix' => 'portfolio-'],
    ];
    $workerLockPath = $cacheDir . '/ranking-worker.lock';
    $lockOwnerPid = null;
    $jobs = [];
    foreach (glob('/proc/[0-9]*/cmdline') ?: [] as $cmdlinePath) {
        $raw = @file_get_contents($cmdlinePath);
        if ($raw === false || $raw === '') continue;
        $args = array_values(array_filter(explode("\0", $raw), static fn (string $arg): bool => $arg !== ''));
        $script = isset($args[1]) ? basename($args[1]) : '';
        if (!isset($workerTypes[$script])) continue;
        $pid = (int) basename(dirname($cmdlinePath));
        foreach (glob('/proc/' . $pid . '/fd/*') ?: [] as $descriptorPath) {
            if (@readlink($descriptorPath) !== $workerLockPath) continue;
            $fdInfo = @file_get_contents('/proc/' . $pid . '/fdinfo/' . basename($descriptorPath));
            if (is_string($fdInfo) && preg_match('/^lock:\s+\d+:\s+FLOCK\s+ADVISORY\s+WRITE\s+(\d+)/m', $fdInfo, $ownerMatch)
                && (int) $ownerMatch[1] === $pid) {
                $lockOwnerPid = (int) $ownerMatch[1];
            }
        }

        $options = [];
        for ($index = 2, $count = count($args); $index + 1 < $count; $index++) {
            if (str_starts_with($args[$index], '--')) {
                $options[substr($args[$index], 2)] = $args[++$index];
            }
        }
        $key = (string) ($options['cache-key'] ?? '');
        if (!preg_match('/^[a-f0-9]{64}$/', $key)) continue;
        $type = $workerTypes[$script]['type'];
        $statusPath = $cacheDir . '/' . $workerTypes[$script]['prefix'] . $key . '.status.json';
        $status = is_file($statusPath) ? json_decode((string) file_get_contents($statusPath), true) : null;
        if (!is_array($status)) $status = [];

        $entryConfig = (string) ($options['entry-config'] ?? '');
        if (preg_match('/^entry(?::(long|short))?:(\d+):(\d+)$/', $entryConfig, $entryMatch)) {
            $direction = match ($entryMatch[1] ?? '') {
                'long' => '+',
                'short' => '−',
                default => '±',
            };
            $entry = 'порог ' . $direction . number_format(((int) $entryMatch[3]) / 100, 2, ',', ' ')
                . ' USDT · окно ' . $entryMatch[2];
        } else {
            $entry = match (true) {
                str_starts_with($entryConfig, 'immediate:short') => 'сразу · продажа',
                str_starts_with($entryConfig, 'immediate:long') => 'сразу · покупка',
                default => $entryConfig,
            };
        }
        $label = match ($type) {
            'strategy' => 'Порог входа + TakeProfit & StopLoss · ' . (string) ($options['symbol'] ?? 'тикер') . ' · все пороги входа',
            'symbols' => 'Рейтинг торговых пар · порог ' . ($entry !== '' ? $entry : '—'),
            default => 'Общий рейтинг · все торговые пары',
        };
        $job = [
            'pid' => $pid,
            'type' => $type,
            'label' => $label,
            'start_day' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($options['start-day'] ?? '')) ? $options['start-day'] : null,
            'end_day' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($options['end-day'] ?? '')) ? $options['end-day'] : null,
            'queued' => $pid !== $lockOwnerPid,
            'progress' => $pid === $lockOwnerPid ? max(0, min(100, (int) ($status['progress'] ?? 0))) : 0,
            'pending' => ($status['status'] ?? '') === 'pending',
            'request_fingerprint' => $type . ':' . $key,
        ];
        $jobs[] = $job;
    }

    usort($jobs, static fn (array $left, array $right): int => $left['pid'] <=> $right['pid']);
    $requestCounts = [];
    foreach ($jobs as $job) {
        $requestCounts[$job['request_fingerprint']] = ($requestCounts[$job['request_fingerprint']] ?? 0) + 1;
    }
    foreach ($jobs as &$job) {
        $job['duplicate'] = $requestCounts[$job['request_fingerprint']] > 1;
        unset($job['request_fingerprint']);
    }
    unset($job);
    $active = array_values(array_filter($jobs, static fn (array $job): bool => !$job['queued'] && $job['pending']));
    $waiting = array_values(array_filter($jobs, static fn (array $job): bool => $job['queued']));
    foreach ($active as &$job) unset($job['pending']);
    unset($job);
    foreach ($waiting as &$job) unset($job['pending']);
    unset($job);
    echo json_encode(['active' => $active, 'waiting' => $waiting, 'updated_at' => time()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $error) {
    http_response_code(503);
    echo json_encode(['error' => 'Не удалось получить очередь расчётов.'], JSON_UNESCAPED_UNICODE);
}
