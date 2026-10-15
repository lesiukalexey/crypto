<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require_once __DIR__ . '/ticker-settings-lib.php';

function hedge_coverage_respond(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    exit;
}

try {
    $root = dirname(__DIR__);
    $config = json_decode((string) file_get_contents($root . '/config.json'), true, 512, JSON_THROW_ON_ERROR);
    $tickerSetId = trim((string) ($_GET['ticker_set'] ?? ''));
    $symbols = selectedTickerSet($config, $tickerSetId)['symbols'];
    $startValue = (string) ($_GET['start_day'] ?? '');
    $endValue = (string) ($_GET['end_day'] ?? '');
    $timezone = new DateTimeZone('Europe/Kyiv');
    $startDay = DateTimeImmutable::createFromFormat('!Y-m-d', $startValue, $timezone);
    $endDay = DateTimeImmutable::createFromFormat('!Y-m-d', $endValue, $timezone);
    $startErrors = DateTimeImmutable::getLastErrors();
    $endErrors = DateTimeImmutable::getLastErrors();
    if ($startDay === false || $endDay === false
        || ($startErrors !== false && ($startErrors['warning_count'] > 0 || $startErrors['error_count'] > 0))
        || ($endErrors !== false && ($endErrors['warning_count'] > 0 || $endErrors['error_count'] > 0))
        || $startDay->format('Y-m-d') !== $startValue || $endDay->format('Y-m-d') !== $endValue || $endDay < $startDay) {
        hedge_coverage_respond(['status' => 'error', 'message' => 'Проверьте выбранный диапазон дат.'], 400);
    }

    $coverage = [];
    if ($symbols !== []) {
        $db = $config['mysql'];
        $passwordEnv = (string) ($db['password_env'] ?? '');
        $password = $passwordEnv !== '' ? (getenv($passwordEnv) ?: '') : '';
        if ($password === '') throw new RuntimeException('Не задан пароль подключения к MySQL.');
        $pdo = new PDO(
            sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $db['host'], $db['port'], $db['database']),
            $db['user'], $password,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
        $statement = $pdo->prepare(
            'SELECT MIN(received_at_utc) AS first_quote_utc, MAX(received_at_utc) AS last_quote_utc '
            . 'FROM quote_snapshots WHERE category = ? AND symbol = ? '
            . 'AND received_at_utc >= ? AND received_at_utc < ?'
        );
        $startUtc = $startDay->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.v');
        $endUtc = $endDay->modify('+1 day')->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.v');
        $kyiv = new DateTimeZone('Europe/Kyiv');
        foreach ($symbols as $symbol) {
            $statement->execute([$config['category'], $symbol, $startUtc, $endUtc]);
            $row = $statement->fetch();
            if (!is_string($row['first_quote_utc'] ?? null) || !is_string($row['last_quote_utc'] ?? null)) continue;
            $firstDay = (new DateTimeImmutable($row['first_quote_utc'], new DateTimeZone('UTC')))->setTimezone($kyiv)->setTime(0, 0);
            $lastDayExclusive = (new DateTimeImmutable($row['last_quote_utc'], new DateTimeZone('UTC')))->setTimezone($kyiv)->setTime(0, 0);
            $coverage[$symbol] = [
                'first_day' => $firstDay->format('Y-m-d'),
                'last_day_exclusive' => $lastDayExclusive->format('Y-m-d'),
            ];
        }
    }
    hedge_coverage_respond(['status' => 'ready', 'coverage' => $coverage]);
} catch (Throwable $error) {
    error_log('Hedge symbol coverage failed: ' . $error->getMessage());
    hedge_coverage_respond(['status' => 'error', 'message' => 'Не удалось проверить покрытие котировок тикеров.'], 500);
}
