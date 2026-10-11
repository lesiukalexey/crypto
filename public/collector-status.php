<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require_once __DIR__ . '/ticker-settings-lib.php';

try {
    $config = json_decode((string) file_get_contents(dirname(__DIR__) . '/config.json'), true, 512, JSON_THROW_ON_ERROR);
    $tickerSetId = trim((string) ($_GET['ticker_set'] ?? ''));
    if ($tickerSetId === '') {
        http_response_code(400);
        echo json_encode(['status' => 'unknown', 'label' => 'Выбранный список тикеров не передан'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $tickerSet = selectedTickerSet($config, $tickerSetId);
    if ($tickerSet['id'] !== $tickerSetId) {
        http_response_code(400);
        echo json_encode(['status' => 'unknown', 'label' => 'Выбранный список тикеров не найден'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $symbols = $tickerSet['symbols'];
    if ($symbols === []) {
        echo json_encode(['status' => 'empty', 'label' => 'В выбранном списке нет активных тикеров'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $db = $config['mysql'];
    $password = getenv($db['password_env']) ?: '';
    if ($password === '') {
        throw new RuntimeException('MySQL password is not configured.');
    }

    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $db['host'], $db['port'], $db['database']),
        $db['user'],
        $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
    $symbolPlaceholders = implode(',', array_fill(0, count($symbols), '?'));
    $stmt = $pdo->prepare(
        "SELECT symbol, MAX(received_at_utc) AS latest
         FROM quote_snapshots
         WHERE category = ? AND symbol IN ($symbolPlaceholders)
         GROUP BY symbol
         ORDER BY latest ASC, symbol ASC
         LIMIT 1"
    );
    $stmt->execute([$config['category'], ...$symbols]);
    $oldestLatestQuote = $stmt->fetch();

    if (!is_array($oldestLatestQuote) || !is_string($oldestLatestQuote['latest'] ?? null) || $oldestLatestQuote['latest'] === '') {
        echo json_encode(['status' => 'empty', 'label' => 'Котировок выбранного списка пока нет'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $symbol = strtoupper((string) $oldestLatestQuote['symbol']);
    $latestUtc = new DateTimeImmutable($oldestLatestQuote['latest'], new DateTimeZone('UTC'));
    $kyiv = new DateTimeZone('Europe/Kyiv');
    $nowUtc = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $ageSeconds = max(0, $nowUtc->getTimestamp() - $latestUtc->getTimestamp());
    $status = $ageSeconds > 86400 ? 'stale-red' : ($ageSeconds >= 3600 ? 'fresh-yellow' : 'stale-green');
    $latestKyiv = $latestUtc->setTimezone($kyiv)->format('d.m.Y H:i:s');
    echo json_encode([
        'status' => $status,
        'label' => 'Последняя котировка: ' . $symbol . ' · ' . $latestKyiv,
        'symbol' => $symbol,
        'latest' => $latestKyiv,
        'age_seconds' => $ageSeconds,
        'title' => 'Самая старая последняя котировка списка «' . $tickerSet['name'] . '»: ' . $symbol . ' — ' . $latestKyiv . ' (Europe/Kyiv)',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable) {
    http_response_code(503);
    echo json_encode(['status' => 'unknown', 'label' => 'Не удалось загрузить время котировки'], JSON_UNESCAPED_UNICODE);
}
