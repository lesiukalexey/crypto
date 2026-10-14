<?php
declare(strict_types=1);
require_once __DIR__ . '/strategy-comparison-ranges.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
try {
    $zone = new DateTimeZone('Europe/Kyiv');
    $parseDay = static function (string $value) use ($zone): DateTimeImmutable {
        $day = DateTimeImmutable::createFromFormat('!Y-m-d', $value, $zone);
        if (!$day || $day->format('Y-m-d') !== $value) throw new InvalidArgumentException('Проверьте диапазон дат.');
        return $day;
    };
    $start = $parseDay((string) ($_GET['start_day'] ?? ''));
    $end = $parseDay((string) ($_GET['end_day'] ?? ''));
    $symbol = strtoupper(trim((string) ($_GET['symbol'] ?? '')));
    $entry = trim((string) ($_GET['entry_config'] ?? ''));
    $exit = trim((string) ($_GET['exit_config'] ?? ''));
    $mode = (string) ($_GET['exit_confirmation_mode'] ?? 'immediate');
    if (!preg_match('/^[A-Z0-9]{2,40}$/', $symbol) || $entry === '' || $exit === ''
        || !in_array($mode, ['immediate', 'both_delayed', 'tp_delayed', 'sl_delayed', 'none'], true)) {
        throw new InvalidArgumentException('Выберите торговую пару, порог входа и TP/SL.');
    }
    if ((string) ($_GET['martingale_mode'] ?? 'none') !== 'none') {
        throw new InvalidArgumentException('Сравнение сохранённой доходности доступно в обычном режиме без мартингейла.');
    }
    $limit = 11;
    $ranges = strategyComparisonRanges($start, $end, new DateTimeImmutable('today', $zone), $limit);
    $config = json_decode((string) file_get_contents(dirname(__DIR__) . '/config.json'), true, 512, JSON_THROW_ON_ERROR);
    if (!in_array($symbol, $config['symbols'], true)) throw new InvalidArgumentException('Неизвестная торговая пара.');
    $db = $config['mysql'];
    $password = getenv($db['password_env']) ?: '';
    if ($password === '') throw new RuntimeException('Database unavailable.');
    $pdo = new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $db['host'], $db['port'], $db['database']),
        $db['user'], $password, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $utc = new DateTimeZone('UTC');
    $from = $parseDay($ranges[0]['start_day'])->setTimezone($utc)->format('Y-m-d H:i:s');
    $to = $parseDay($ranges[array_key_last($ranges)]['end_day'])->modify('+1 day')->setTimezone($utc)->format('Y-m-d H:i:s');
    $query = $pdo->prepare('SELECT p.point_minute,p.return_percent,p.trade_count FROM strategy_return_points p '
        . 'JOIN strategy_return_profiles s ON s.strategy_id=p.strategy_id '
        . 'WHERE p.symbol=? AND s.entry_id=? AND s.exit_id=? AND s.exit_confirmation_mode=? AND s.exit_confirmation_snapshots=? '
        . 'AND p.point_minute>=? AND p.point_minute<? ORDER BY p.point_minute');
    $query->execute([$symbol,$entry,$exit,$mode,$mode === 'immediate' ? 0 : (int) ($_GET['exit_confirmation_snapshots'] ?? 60),$from,$to]);
    foreach ($ranges as &$range) {
        $range['factor'] = 1.0;
        $range['point_count'] = 0;
        $range['trade_count'] = 0;
    }
    unset($range);
    while (($point = $query->fetch()) !== false) {
        $day = (new DateTimeImmutable($point['point_minute'], $utc))->setTimezone($zone)->format('Y-m-d');
        foreach ($ranges as &$range) {
            if ($day < $range['start_day'] || $day > $range['end_day']) continue;
            $range['factor'] *= max(0.0, 1.0 + (float) $point['return_percent'] / 100.0);
            $range['point_count']++;
            $range['trade_count'] += (int) $point['trade_count'];
        }
        unset($range);
    }
    foreach ($ranges as &$range) {
        $range['return_percent'] = $range['point_count'] > 0 ? ($range['factor'] - 1.0) * 100.0 : null;
        unset($range['factor']);
    }
    unset($range);
    echo json_encode(['status'=>'ready','symbol'=>$symbol,'entry_id'=>$entry,'exit_id'=>$exit,
        'range_days'=>(int) $start->diff($end)->format('%a') + 1,'ranges'=>$ranges], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    http_response_code($error instanceof InvalidArgumentException ? 400 : 503);
    if (!$error instanceof InvalidArgumentException) error_log('Strategy range comparison: ' . $error->getMessage());
    echo json_encode(['status'=>'error','message'=>$error instanceof InvalidArgumentException ? $error->getMessage() : 'Не удалось загрузить сравнение диапазонов.'], JSON_UNESCAPED_UNICODE);
}
