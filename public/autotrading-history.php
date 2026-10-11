<?php
declare(strict_types=1);

header('Cache-Control: no-store');
header('Content-Type: application/json; charset=utf-8');

function historyResponse(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    exit;
}

if (!in_array((string) ($_SERVER['REMOTE_ADDR'] ?? ''), ['127.0.0.1', '::1'], true)) {
    historyResponse(['status' => 'error', 'message' => 'История сделок доступна только с этого компьютера.'], 403);
}

$mode = (string) ($_GET['mode'] ?? '');
if (!in_array($mode, ['demo', 'real'], true)) historyResponse(['status' => 'error', 'message' => 'Выберите режим Bitget Demo или Bitget Real.'], 422);
$symbol = strtoupper(trim((string) ($_GET['symbol'] ?? '')));
$strategyId = strtolower(trim((string) ($_GET['strategy_id'] ?? '')));
if (!preg_match('/^[A-Z0-9]{2,40}$/', $symbol)) historyResponse(['status' => 'error', 'message' => 'Укажите корректный тикер.'], 422);
if ($strategyId !== '' && !preg_match('/^(?:[a-f0-9]{64}|legacy)$/', $strategyId)) historyResponse(['status' => 'error', 'message' => 'Выберите сохранённую стратегию.'], 422);

$datePattern = '/^\d{4}-\d{2}-\d{2}$/';
$startDate = (string) ($_GET['start_date'] ?? '');
$endDate = (string) ($_GET['end_date'] ?? '');
$kyiv = new DateTimeZone('Europe/Kyiv');
if (!preg_match($datePattern, $startDate) || !preg_match($datePattern, $endDate)) historyResponse(['status' => 'error', 'message' => 'Укажите даты периода.'], 422);
$start = DateTimeImmutable::createFromFormat('!Y-m-d', $startDate, $kyiv);
$end = DateTimeImmutable::createFromFormat('!Y-m-d', $endDate, $kyiv);
if (!$start || !$end || $start->format('Y-m-d') !== $startDate || $end->format('Y-m-d') !== $endDate || $start > $end) {
    historyResponse(['status' => 'error', 'message' => 'Начальная дата должна быть не позже конечной.'], 422);
}
$fromMs = (int) $start->setTimezone(new DateTimeZone('UTC'))->format('U') * 1000;
$untilMs = (int) $end->modify('+1 day')->setTimezone(new DateTimeZone('UTC'))->format('U') * 1000;

$uid = function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();
$runtimeRoot = getenv('BITGET_AUTOTRADING_DIR') ?: '/var/tmp';
$runtime = rtrim($runtimeRoot, '/') . '/bitget-autotrading-' . $uid;
$statePaths = ['demo' => $runtime . '/state-demo.sqlite', 'real' => $runtime . '/state-live.sqlite', 'paper' => $runtime . '/state.sqlite'];

function readModeHistory(string $path, string $symbol, string $strategyId, int $fromMs, int $untilMs): array
{
    if (!is_file($path) || is_link($path)) return ['quotes' => [], 'trades' => [], 'available' => false];
    $database = new PDO('sqlite:file:' . $path . '?mode=ro', null, null, [PDO::ATTR_TIMEOUT => 5]);
    $database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $database->exec('PRAGMA query_only=ON');
    $tables = $database->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('trades', $tables, true)) return ['quotes' => [], 'trades' => [], 'available' => true];

    $tradesSql = 'SELECT id,symbol,side,quantity,entry_price,exit_price,entry_ts,exit_ts,pnl,release_digest,strategy_id,leg FROM trades WHERE symbol=:symbol AND exit_ts>=:from AND exit_ts<:until';
    if ($strategyId !== '') $tradesSql .= ' AND strategy_id=:strategy_id';
    $tradesSql .= ' ORDER BY entry_ts,exit_ts,id';
    $tradeQuery = $database->prepare($tradesSql);
    $tradeQuery->bindValue(':symbol', $symbol, PDO::PARAM_STR);
    $tradeQuery->bindValue(':from', $fromMs, PDO::PARAM_INT);
    $tradeQuery->bindValue(':until', $untilMs, PDO::PARAM_INT);
    if ($strategyId !== '') $tradeQuery->bindValue(':strategy_id', $strategyId, PDO::PARAM_STR);
    $tradeQuery->execute();
    $trades = $tradeQuery->fetchAll(PDO::FETCH_ASSOC);

    $quotes = [];
    if (in_array('quotes', $tables, true)) {
        $countQuery = $database->prepare('SELECT COUNT(*) FROM quotes WHERE symbol=:symbol AND ts>=:from AND ts<:until');
        $countQuery->execute([':symbol' => $symbol, ':from' => $fromMs, ':until' => $untilMs]);
        $count = (int) $countQuery->fetchColumn();
        $step = max(1, (int) ceil($count / 2500));
        $quoteQuery = $database->prepare('SELECT ts,last FROM quotes WHERE symbol=:symbol AND ts>=:from AND ts<:until AND rowid % :step=0 ORDER BY ts');
        $quoteQuery->bindValue(':symbol', $symbol, PDO::PARAM_STR);
        $quoteQuery->bindValue(':from', $fromMs, PDO::PARAM_INT);
        $quoteQuery->bindValue(':until', $untilMs, PDO::PARAM_INT);
        $quoteQuery->bindValue(':step', $step, PDO::PARAM_INT);
        $quoteQuery->execute();
        $quotes = $quoteQuery->fetchAll(PDO::FETCH_ASSOC);
        if ($count > 0) {
            $firstQuery = $database->prepare('SELECT ts,last FROM quotes WHERE symbol=:symbol AND ts>=:from AND ts<:until ORDER BY ts LIMIT 1');
            $firstQuery->execute([':symbol' => $symbol, ':from' => $fromMs, ':until' => $untilMs]);
            $first = $firstQuery->fetch(PDO::FETCH_ASSOC);
            $lastQuery = $database->prepare('SELECT ts,last FROM quotes WHERE symbol=:symbol AND ts>=:from AND ts<:until ORDER BY ts DESC LIMIT 1');
            $lastQuery->execute([':symbol' => $symbol, ':from' => $fromMs, ':until' => $untilMs]);
            $last = $lastQuery->fetch(PDO::FETCH_ASSOC);
            if ($first) $quotes[] = $first;
            if ($last) $quotes[] = $last;
            if ($quotes !== []) {
                $unique = [];
                foreach ($quotes as $point) $unique[(string) $point['ts']] = $point;
                $quotes = array_values($unique);
                usort($quotes, static fn (array $left, array $right): int => (int) $left['ts'] <=> (int) $right['ts']);
            }
        }
    }
    return ['quotes' => $quotes, 'trades' => $trades, 'available' => true];
}

try {
    $actual = readModeHistory($statePaths[$mode], $symbol, $strategyId, $fromMs, $untilMs);
    $paper = $mode === 'demo' && preg_match('/^[a-f0-9]{64}$/', $strategyId)
        ? readModeHistory($statePaths['paper'], $symbol, $strategyId, $fromMs, $untilMs)
        : ['quotes' => [], 'trades' => [], 'available' => false];
    historyResponse([
        'status' => 'ready',
        'mode' => $mode,
        'symbol' => $symbol,
        'strategy_id' => $strategyId,
        'actual' => $actual,
        'paper' => $paper,
        'period' => ['start_date' => $startDate, 'end_date' => $endDate],
    ]);
} catch (Throwable $error) {
    error_log('Autotrading history read failed: ' . get_class($error));
    historyResponse(['status' => 'error', 'message' => 'Не удалось прочитать сохранённые сделки. Проверьте локальное состояние автоторговли.'], 500);
}
