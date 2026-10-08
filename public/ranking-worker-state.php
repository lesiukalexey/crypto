<?php
declare(strict_types=1);

function rankingWorkerLockOwner(string $cacheDir): ?array
{
    $lockPath = $cacheDir . '/ranking-worker.lock';
    foreach (glob('/proc/[0-9]*/cmdline') ?: [] as $cmdlinePath) {
        $commandLine = @file_get_contents($cmdlinePath);
        if (!is_string($commandLine) || $commandLine === '') continue;
        $arguments = array_values(array_filter(explode("\0", $commandLine), static fn (string $argument): bool => $argument !== ''));
        $script = isset($arguments[1]) ? basename($arguments[1]) : '';
        if (!in_array($script, ['strategy_ranking.py', 'symbol_ranking.py', 'portfolio_ranking.py'], true)) continue;
        $pid = (int) basename(dirname($cmdlinePath));
        $ownsLock = false;
        foreach (glob('/proc/' . $pid . '/fd/*') ?: [] as $descriptorPath) {
            if (@readlink($descriptorPath) !== $lockPath) continue;
            $fdInfo = @file_get_contents('/proc/' . $pid . '/fdinfo/' . basename($descriptorPath));
            if (is_string($fdInfo) && preg_match('/^lock:\s+\d+:\s+FLOCK\s+ADVISORY\s+WRITE\s+(\d+)/m', $fdInfo, $match)
                && (int) $match[1] === $pid) {
                $ownsLock = true;
                break;
            }
        }
        if (!$ownsLock) continue;
        $options = [];
        for ($index = 2, $count = count($arguments); $index + 1 < $count; $index++) {
            if (str_starts_with($arguments[$index], '--')) $options[substr($arguments[$index], 2)] = $arguments[++$index];
        }
        return ['pid' => $pid, 'script' => $script, 'cache_key' => (string) ($options['cache-key'] ?? '')];
    }
    return null;
}
