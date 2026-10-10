<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require_once __DIR__ . '/ticker-settings-lib.php';

function candleCompletenessRespond(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function candleCompletenessDate(string $value, DateTimeZone $timezone): ?DateTimeImmutable
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, $timezone);
    $errors = DateTimeImmutable::getLastErrors();
    if ($date === false
        || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
        || $date->format('Y-m-d') !== $value) return null;
    return $date;
}

try {
    $config = json_decode((string) file_get_contents(dirname(__DIR__) . '/config.json'), true, 512, JSON_THROW_ON_ERROR);
    $timezone = new DateTimeZone('Europe/Kyiv');
    $startValue = (string) ($_GET['start_day'] ?? '');
    $endValue = (string) ($_GET['end_day'] ?? '');
    $startDay = candleCompletenessDate($startValue, $timezone);
    $endDay = candleCompletenessDate($endValue, $timezone);
    if ($startDay === null || $endDay === null || $endDay < $startDay) {
        candleCompletenessRespond(['status' => 'error', 'message' => 'Проверьте выбранный диапазон дат.'], 400);
    }

    $enabled = enabledTickerSymbols($config);
    $selected = strtoupper(trim((string) ($_GET['symbol'] ?? '')));
    if (!in_array($selected, $enabled, true)) {
        candleCompletenessRespond(['status' => 'error', 'message' => 'Пара выключена или не настроена.'], 400);
    }
    if ($enabled === []) {
        candleCompletenessRespond(['status' => 'ready', 'symbol_incomplete' => false, 'all_incomplete' => false, 'incomplete_symbols' => [], 'checked_days' => 0]);
    }

    $db = $config['mysql'];
    $password = getenv($db['password_env']) ?: '';
    if ($password === '') throw new RuntimeException('MySQL password is not configured.');
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $db['host'], $db['port'], $db['database']),
        $db['user'], $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );

    $nowKyiv = new DateTimeImmutable('now', $timezone);
    $today = $nowKyiv->setTime(0, 0);
    $nowUtc = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $completeUntilUtc = $nowUtc->setTime((int) $nowUtc->format('H'), (int) $nowUtc->format('i'), 0);
    $symbolsSql = implode(',', array_fill(0, count($enabled), '?'));
    $stmt = $pdo->prepare(
        "SELECT symbol, COUNT(DISTINCT DATE_FORMAT(received_at_utc, '%Y-%m-%d %H:%i')) AS minute_count
         FROM quote_snapshots
         WHERE category = ? AND symbol IN ($symbolsSql)
           AND received_at_utc >= ? AND received_at_utc < ?
         GROUP BY symbol"
    );

    $incomplete = [];
    $checkedDays = 0;
    $day = $startDay;
    $lastDay = $endDay < $today ? $endDay : $today;
    while ($day <= $lastDay) {
        $dayEnd = $day->modify('+1 day');
        $dayStartUtc = $day->setTimezone(new DateTimeZone('UTC'));
        $dayEndUtc = $dayEnd->setTimezone(new DateTimeZone('UTC'));
        $expectedUntilUtc = $dayEndUtc < $completeUntilUtc ? $dayEndUtc : $completeUntilUtc;
        $expectedMinutes = intdiv(max(0, $expectedUntilUtc->getTimestamp() - $dayStartUtc->getTimestamp()), 60);
        if ($expectedMinutes > 0) {
            $stmt->execute([
                $config['category'], ...$enabled,
                $dayStartUtc->format('Y-m-d H:i:s'), $expectedUntilUtc->format('Y-m-d H:i:s'),
            ]);
            $counts = [];
            foreach ($stmt->fetchAll() as $row) $counts[strtoupper((string) $row['symbol'])] = (int) $row['minute_count'];
            foreach ($enabled as $symbol) {
                if (($counts[$symbol] ?? 0) < $expectedMinutes) $incomplete[$symbol] = true;
            }
            $checkedDays++;
        }
        $day = $day->modify('+1 day');
    }

    $incompleteSymbols = array_keys($incomplete);
    candleCompletenessRespond([
        'status' => 'ready',
        'symbol_incomplete' => isset($incomplete[$selected]),
        'all_incomplete' => $incompleteSymbols !== [],
        'incomplete_symbols' => $incompleteSymbols,
        'checked_days' => $checkedDays,
    ]);
} catch (Throwable) {
    candleCompletenessRespond(['status' => 'error', 'message' => 'Не удалось проверить полноту минутных котировок.'], 503);
}
