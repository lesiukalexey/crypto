<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

try {
    $config = json_decode((string) file_get_contents(dirname(__DIR__) . '/config.json'), true, 512, JSON_THROW_ON_ERROR);
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
    $stmt = $pdo->prepare('SELECT MAX(received_at_utc) FROM quote_snapshots WHERE category = ?');
    $stmt->execute([$config['category']]);
    $latest = $stmt->fetchColumn();

    if (!is_string($latest) || $latest === '') {
        echo json_encode(['status' => 'empty', 'label' => 'Котировок пока нет']);
        exit;
    }

    $latestUtc = new DateTimeImmutable($latest, new DateTimeZone('UTC'));
    $ageSeconds = max(0, time() - $latestUtc->getTimestamp());
    $interval = max(1, (float) ($config['poll_interval_seconds'] ?? 60));
    $activeThreshold = max(180, (int) ceil($interval * 3));
    $delayedThreshold = max(600, (int) ceil($interval * 10));
    if ($ageSeconds <= $activeThreshold) {
        $status = 'active';
        $label = 'Сбор котировок работает';
    } elseif ($ageSeconds <= $delayedThreshold) {
        $status = 'delayed';
        $label = 'Котировки поступают с задержкой';
    } else {
        $status = 'stopped';
        $label = 'Новых котировок давно нет';
    }

    $latestKyiv = $latestUtc->setTimezone(new DateTimeZone('Europe/Kyiv'))->format('d.m.Y H:i:s');
    echo json_encode([
        'status' => $status,
        'label' => $label,
        'latest' => $latestKyiv,
        'age_seconds' => $ageSeconds,
        'title' => 'Последний снимок: ' . $latestKyiv . ' · ' . $ageSeconds . ' сек. назад',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable) {
    http_response_code(503);
    echo json_encode(['status' => 'unknown', 'label' => 'Не удалось проверить сборщик'], JSON_UNESCAPED_UNICODE);
}
