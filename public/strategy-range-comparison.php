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
    $params = $_GET;
    $params['include_funding'] = (string) ($_GET['include_funding'] ?? '1');
    $directory = (getenv('HOME') ?: '/home/alex') . '/.ai/home/.local/bitget-backtest-cache';
    $job = tempnam($directory, '.range-job-');
    if ($job === false) throw new RuntimeException('Не удалось подготовить сравнение.');
    try {
        file_put_contents($job, json_encode(['symbol'=>$symbol,'params'=>$params,'ranges'=>$ranges], JSON_THROW_ON_ERROR));
        chmod($job,0600);
        $root = dirname(__DIR__);
        $output=[];$code=0;
        exec(escapeshellarg($root . '/.venv/bin/python') . ' ' . escapeshellarg($root . '/strategy_range_replay.py') . ' ' . escapeshellarg($job), $output, $code);
        if ($code !== 0) throw new RuntimeException('Не удалось пересчитать сравниваемые диапазоны.');
        $ranges=json_decode(implode("\n",$output),true,512,JSON_THROW_ON_ERROR);
    } finally { unlink($job); }
    echo json_encode(['status'=>'ready','symbol'=>$symbol,'entry_id'=>$entry,'exit_id'=>$exit,
        'range_days'=>(int) $start->diff($end)->format('%a') + 1,'ranges'=>$ranges], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    http_response_code($error instanceof InvalidArgumentException ? 400 : 503);
    if (!$error instanceof InvalidArgumentException) error_log('Strategy range comparison: ' . $error->getMessage());
    echo json_encode(['status'=>'error','message'=>$error instanceof InvalidArgumentException ? $error->getMessage() : 'Не удалось загрузить сравнение диапазонов.'], JSON_UNESCAPED_UNICODE);
}
