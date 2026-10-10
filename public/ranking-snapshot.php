<?php
declare(strict_types=1);

/**
 * Return the first saved quote ID per symbol for a date range and cache
 * generation. Every ranking mode then reuses this same data cut until reset.
 */
function rankingSnapshotLatestIds(PDO $pdo, string $category, string $startUtc, string $endUtc): array
{
    $statement = $pdo->prepare('SELECT symbol, MAX(id) AS max_id FROM quote_snapshots WHERE category = ? AND received_at_utc >= ? AND received_at_utc < ? GROUP BY symbol ORDER BY symbol');
    $statement->execute([$category, $startUtc, $endUtc]);
    $snapshotIds = [];
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $snapshotIds[(string) $row['symbol']] = (int) $row['max_id'];
    }
    return $snapshotIds;
}

function rankingSnapshotNeedsRefresh(PDO $pdo, string $category, string $startUtc, string $endUtc, array $snapshotIds): bool
{
    $latestIds = rankingSnapshotLatestIds($pdo, $category, $startUtc, $endUtc);
    foreach ($latestIds as $symbol => $maxId) {
        if (($snapshotIds[$symbol] ?? 0) < $maxId) return true;
    }
    return false;
}

function rankingSnapshotRevision(string $cacheDir, string $category, string $startDay, string $endDay, string $generation): ?string
{
    $snapshotKey = hash('sha256', json_encode(['ranking-snapshot-v1', $category, $startDay, $endDay, $generation], JSON_THROW_ON_ERROR));
    $snapshotPath = $cacheDir . '/snapshot-' . $snapshotKey . '.json';
    if (!is_file($snapshotPath)) return null;
    $saved = json_decode((string) file_get_contents($snapshotPath), true);
    $revision = is_array($saved) ? ($saved['refreshed_snapshot_sha256'] ?? null) : null;
    return is_string($revision) && $revision !== '' ? $revision : null;
}

function rankingSnapshotIds(PDO $pdo, string $cacheDir, string $category, string $startDay, string $endDay, string $startUtc, string $endUtc, string $generation, bool $refreshSnapshot = false): array
{
    $snapshotKey = hash('sha256', json_encode([
        'ranking-snapshot-v1', $category, $startDay, $endDay, $generation,
    ], JSON_THROW_ON_ERROR));
    $snapshotPath = $cacheDir . '/snapshot-' . $snapshotKey . '.json';
    $lock = fopen($snapshotPath . '.lock', 'c');
    if ($lock === false || !flock($lock, LOCK_EX)) {
        if (is_resource($lock)) fclose($lock);
        throw new RuntimeException('Не удалось закрепить снимок данных рейтинга.');
    }

    try {
        if (is_file($snapshotPath)) {
            $saved = json_decode((string) file_get_contents($snapshotPath), true);
            if (is_array($saved)
                && ($saved['category'] ?? null) === $category
                && ($saved['start_day'] ?? null) === $startDay
                && ($saved['end_day'] ?? null) === $endDay
                && ($saved['generation'] ?? null) === $generation
                && is_array($saved['snapshot_ids'] ?? null)) {
                $snapshotIds = array_map('intval', $saved['snapshot_ids']);
                if (!$refreshSnapshot) {
                    if ($snapshotIds !== []) return $snapshotIds;
                    $latestIds = rankingSnapshotLatestIds($pdo, $category, $startUtc, $endUtc);
                    if ($latestIds === []) return $snapshotIds;
                    // Historical imports can populate a date range after its
                    // original empty snapshot was cached. Hydrate that empty
                    // snapshot so ranking workers see the newly available rows.
                    $snapshotIds = $latestIds;
                } else {
                    $snapshotIds = rankingSnapshotLatestIds($pdo, $category, $startUtc, $endUtc);
                }
                $saved['snapshot_ids'] = $snapshotIds;
                $saved['refreshed_snapshot_sha256'] = hash('sha256', json_encode($snapshotIds, JSON_THROW_ON_ERROR));
                $payload = json_encode($saved, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
                $temporaryPath = tempnam($cacheDir, '.ranking-snapshot-');
                if ($temporaryPath === false || file_put_contents($temporaryPath, $payload, LOCK_EX) === false || !rename($temporaryPath, $snapshotPath)) {
                    if (is_string($temporaryPath)) @unlink($temporaryPath);
                    throw new RuntimeException('Не удалось обновить снимок данных рейтинга.');
                }
                return $snapshotIds;
            }
        }

        $snapshotIds = rankingSnapshotLatestIds($pdo, $category, $startUtc, $endUtc);
        $payload = json_encode([
            'category' => $category,
            'start_day' => $startDay,
            'end_day' => $endDay,
            'generation' => $generation,
            'snapshot_ids' => $snapshotIds,
            ...($refreshSnapshot ? ['refreshed_snapshot_sha256' => hash('sha256', json_encode($snapshotIds, JSON_THROW_ON_ERROR))] : []),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $temporaryPath = tempnam($cacheDir, '.ranking-snapshot-');
        if ($temporaryPath === false
            || file_put_contents($temporaryPath, $payload, LOCK_EX) === false
            || !rename($temporaryPath, $snapshotPath)) {
            if (is_string($temporaryPath)) @unlink($temporaryPath);
            throw new RuntimeException('Не удалось сохранить снимок данных рейтинга.');
        }
        return $snapshotIds;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}
