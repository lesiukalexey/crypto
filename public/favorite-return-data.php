<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function favoriteReturnRespond(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    exit;
}

function favoriteReturnNumberMatches(mixed $left, mixed $right): bool
{
    return is_numeric($left) && is_numeric($right) && abs((float) $left - (float) $right) < 0.0000001;
}

try {
    $favoriteId = trim((string) ($_GET['favorite_id'] ?? ''));
    if ($favoriteId === '') favoriteReturnRespond(['status' => 'missing']);

    $favoritesPath = (getenv('HOME') ?: '/home/alex') . '/.ai/home/.local/bitget-favorites.json';
    $favorites = is_file($favoritesPath) ? json_decode((string) file_get_contents($favoritesPath), true) : [];
    $favorite = null;
    foreach (is_array($favorites) ? $favorites : [] as $candidate) {
        if (is_array($candidate) && (string) ($candidate['id'] ?? '') === $favoriteId) {
            $favorite = $candidate;
            break;
        }
    }
    if (!is_array($favorite)) favoriteReturnRespond(['status' => 'missing']);
    $type = (string) ($favorite['type'] ?? 'strategy');
    $params = is_array($favorite['params'] ?? null) ? $favorite['params'] : [];
    $symbol = strtoupper(trim((string) ($params['symbol'] ?? '')));
    $entryId = trim((string) ($params['entry_config'] ?? $params['ranking_entry_config'] ?? ''));
    $exitId = trim((string) ($params['exit_config'] ?? ''));
    $exitConfirmationMode = (string) ($params['exit_confirmation_mode'] ?? 'immediate');
    $exitConfirmationSnapshots = (int) ($params['exit_confirmation_snapshots'] ?? 0);
    if ($exitConfirmationMode === 'immediate') $exitConfirmationSnapshots = 0;
    if (!in_array($type, ['strategy', 'global_top', 'ticker_leader'], true) || $symbol === '' || $entryId === '' || $exitId === '') {
        favoriteReturnRespond(['status' => 'missing']);
    }

    $config = json_decode((string) file_get_contents(dirname(__DIR__) . '/config.json'), true, 512, JSON_THROW_ON_ERROR);
    $database = $config['mysql'] ?? [];
    $passwordName = (string) ($database['password_env'] ?? '');
    $password = $passwordName !== '' ? (getenv($passwordName) ?: '') : '';
    if ($password === '' || !isset($database['host'], $database['port'], $database['database'], $database['user'])) {
        throw new RuntimeException('MySQL connection is not configured.');
    }
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $database['host'], $database['port'], $database['database']),
        $database['user'], $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC],
    );
    $eventsQuery = $pdo->prepare(
        'SELECT p.point_minute,p.return_percent,p.trade_count '
        . 'FROM strategy_return_points p JOIN strategy_return_profiles s ON s.strategy_id=p.strategy_id '
        . 'WHERE p.symbol=? AND s.entry_id=? AND s.exit_id=? AND s.exit_confirmation_mode=? AND s.exit_confirmation_snapshots=? ORDER BY p.point_minute'
    );
    $eventsQuery->execute([$symbol, $entryId, $exitId, $exitConfirmationMode, $exitConfirmationSnapshots]);
    $events = $eventsQuery->fetchAll();
    if ($events === []) favoriteReturnRespond(['status' => 'missing']);

    $factor = 1.0;
    $closedCount = 0;
    foreach ($events as $event) {
        $periodFactor = max(0.0, 1.0 + (float) $event['return_percent'] / 100.0);
        $factor *= $periodFactor;
        $closedCount += (int) $event['trade_count'];
    }
    $utc = new DateTimeZone('UTC');
    $kyiv = new DateTimeZone('Europe/Kyiv');
    $first = new DateTimeImmutable((string) $events[0]['point_minute'], $utc);
    $lastEvent = $events[array_key_last($events)];
    $last = new DateTimeImmutable((string) $lastEvent['point_minute'], $utc);
    $format = static fn (DateTimeImmutable $date): string => $date->setTimezone($kyiv)->format('d.m.Y H:i');
    favoriteReturnRespond([
        'status' => 'ready',
        'return_percent' => sprintf('%.12f', ($factor - 1.0) * 100.0),
        'start_at' => $format($first),
        'end_at' => $format($last),
        'point_count' => count($events),
        'closed_trade_count' => $closedCount,
        'provisional' => false,
    ]);
} catch (Throwable $error) {
    error_log('Unable to read favorite strategy return history: ' . $error->getMessage());
    favoriteReturnRespond(['status' => 'error', 'message' => 'Не удалось загрузить рассчитанную доходность.'], 500);
}
