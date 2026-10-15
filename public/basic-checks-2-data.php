<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require_once __DIR__ . '/ticker-settings-lib.php';

function b2Respond(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    exit;
}

function b2ValidKey(string $value): bool
{
    return preg_match('/^[a-f0-9]{64}$/', $value) === 1;
}

function b2Database(array $config): PDO
{
    $db = $config['mysql'] ?? [];
    $passwordName = (string) ($db['password_env'] ?? '');
    $password = $passwordName !== '' ? (getenv($passwordName) ?: '') : '';
    if ($password === '') throw new RuntimeException('Не задан пароль подключения к MySQL.');
    return new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $db['host'], $db['port'], $db['database']),
        $db['user'], $password, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
}

function b2CacheRoot(): string
{
    $root = (getenv('HOME') ?: '/home/alex') . '/.ai/home/.local/bitget-backtest-cache/basic-checks-2';
    if (!is_dir($root) && !mkdir($root, 0700, true) && !is_dir($root)) throw new RuntimeException('Не удалось создать приватный каталог расчётов.');
    @chmod($root, 0700);
    return $root;
}

function b2Coverage(PDO $pdo, string $category, array $symbols): array
{
    if (!$symbols) return [];
    $placeholders = implode(',', array_fill(0, count($symbols), '?'));
    $stmt = $pdo->prepare("SELECT symbol, MIN(received_at_utc) AS first_at, MAX(received_at_utc) AS last_at, MAX(id) AS max_id FROM quote_snapshots WHERE category=? AND symbol IN ($placeholders) GROUP BY symbol");
    $stmt->execute([$category, ...$symbols]);
    $result = [];
    foreach ($stmt->fetchAll() as $row) $result[(string)$row['symbol']] = [
        'first_at_utc'=>(string)$row['first_at'], 'last_at_utc'=>(string)$row['last_at'], 'max_id'=>(int)$row['max_id'],
    ];
    return $result;
}

try {
    $config = json_decode((string)file_get_contents(dirname(__DIR__) . '/config.json'), true, 512, JSON_THROW_ON_ERROR);
    $selection = selectedTickerSet($config, trim((string)($_REQUEST['ticker_set'] ?? '')));
    $action = (string)($_REQUEST['action'] ?? 'init');
    if ($action === 'init') {
        $pdo = b2Database($config);
        $coverage = b2Coverage($pdo, (string)$config['category'], $selection['symbols']);
        $pdo = null;
        b2Respond(['status'=>'ready', 'set_id'=>$selection['id'], 'set_name'=>$selection['name'],
            'sets'=>$selection['sets'], 'symbols'=>$selection['symbols'], 'coverage'=>$coverage]);
    }

    $root = b2CacheRoot();
    if ($action === 'start') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') b2Respond(['status'=>'error','message'=>'Расчёт запускается POST-запросом.'],405);
        $symbols = $selection['symbols'];
        $scope = (string)($_POST['scope'] ?? 'single');
        $symbol = strtoupper(trim((string)($_POST['symbol'] ?? '')));
        $interval = (string)($_POST['interval'] ?? 'two_weeks');
        $signalMode = (string)($_POST['signal_mode'] ?? 'exact');
        $directionMode = (string)($_POST['direction_mode'] ?? 'follow');
        $exitMode = (string)($_POST['exit_mode'] ?? 'immediate');
        $balance = trim((string)($_POST['balance'] ?? '100'));
        $fee = trim((string)($_POST['fee'] ?? '0.06'));
        if (!in_array($scope,['single','list'],true) || !in_array($interval,['week','two_weeks','month'],true)
            || !in_array($signalMode,['exact','within'],true) || !in_array($directionMode,['follow','reverse'],true)
            || !in_array($exitMode,['immediate','none','tp_delayed','sl_delayed','both_delayed'],true)
            || ($scope === 'single' && !in_array($symbol,$symbols,true))
            || !preg_match('/^\d{1,9}(?:\.\d{1,2})?$/',$balance) || (float)$balance <= 0 || (float)$balance > 1000000000
            || !preg_match('/^\d{1,3}(?:\.\d{1,4})?$/',$fee) || (float)$fee < 0 || (float)$fee > 5) {
            b2Respond(['status'=>'error','message'=>'Проверьте тикер, интервал, режим стратегии, баланс и комиссию.'],400);
        }
        $pdo = b2Database($config);
        $coverage = b2Coverage($pdo, (string)$config['category'], $symbols);
        $pdo = null;
        $revision = [];
        foreach ($symbols as $member) $revision[$member] = $coverage[$member]['max_id'] ?? 0;
        $request = [
            'scope'=>$scope,'symbol'=>$symbol,'ticker_set'=>$selection['id'],'symbols'=>$symbols,
            'interval'=>$interval,'signal_mode'=>$signalMode,'direction_mode'=>$directionMode,'exit_mode'=>$exitMode,
            'balance'=>$balance,'fee'=>$fee,'include_funding'=>($_POST['include_funding'] ?? '1') === '1',
            'slippage_bps'=>(float)($_POST['slippage_bps'] ?? 0),'revision'=>$revision,
        ];
        if ($request['slippage_bps'] < 0 || $request['slippage_bps'] >= 10000) b2Respond(['status'=>'error','message'=>'Некорректное проскальзывание.'],400);
        $request['cache_version'] = 'basic-checks-2-walk-forward-v1';
        $runKey = hash('sha256', json_encode($request, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
        $runDir = $root . '/' . $runKey;
        if (!is_dir($runDir) && !mkdir($runDir,0700,true) && !is_dir($runDir)) throw new RuntimeException('Не удалось подготовить расчёт.');
        @chmod($runDir,0700);
        $statusPath = $runDir . '/status.json';
        $saved = is_file($statusPath) ? json_decode((string)file_get_contents($statusPath),true) : null;
        if (is_array($saved) && in_array($saved['status'] ?? '',['running','ready'],true)) {
            b2Respond(['status'=>'ready','run_key'=>$runKey,'run_status'=>$saved,'cache_hit'=>true]);
        }
        @unlink($runDir . '/cancel');
        file_put_contents($runDir . '/request.json', json_encode($request,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR), LOCK_EX);
        @chmod($runDir . '/request.json',0600);
        $worker = dirname(__DIR__) . '/basic_checks_2_worker.py';
        $python = dirname(__DIR__) . '/.venv/bin/python';
        if (!is_executable($python)) $python = '/usr/bin/python3';
        file_put_contents($statusPath, json_encode(['status'=>'running','progress'=>0,'done'=>0,'total'=>0,'message'=>'Запускаю расчёт'],JSON_UNESCAPED_UNICODE), LOCK_EX);
        $command = 'nohup ' . escapeshellarg($python) . ' ' . escapeshellarg($worker) . ' --request ' . escapeshellarg($runDir . '/request.json') . ' >>' . escapeshellarg($runDir . '/worker.log') . ' 2>&1 & echo $!';
        $pid = trim((string)shell_exec($command));
        if (!preg_match('/^\d+$/',$pid)) b2Respond(['status'=>'error','message'=>'Не удалось запустить фоновый расчёт.'],500);
        b2Respond(['status'=>'ready','run_key'=>$runKey,'run_status'=>['status'=>'running','progress'=>0,'message'=>'Запускаю расчёт']]);
    }

    $runKey = (string)($_REQUEST['run_key'] ?? '');
    if (!b2ValidKey($runKey)) b2Respond(['status'=>'error','message'=>'Некорректный ключ расчёта.'],400);
    $runDir = $root . '/' . $runKey;
    if (!is_dir($runDir)) b2Respond(['status'=>'error','message'=>'Расчёт не найден.'],404);
    if ($action === 'stop') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') b2Respond(['status'=>'error','message'=>'Остановка требует POST-запроса.'],405);
        file_put_contents($runDir . '/cancel','stop',LOCK_EX);
        b2Respond(['status'=>'ready','message'=>'Остановка запрошена.']);
    }
    $status = is_file($runDir . '/status.json') ? json_decode((string)file_get_contents($runDir . '/status.json'),true) : null;
    if (!is_array($status)) b2Respond(['status'=>'error','message'=>'Статус расчёта пока недоступен.'],503);
    if ($action === 'status') {
        $status['run_key'] = $runKey;
        $status['results'] = [];
        if (is_file($runDir . '/results.sqlite')) {
            $db = new PDO('sqlite:' . $runDir . '/results.sqlite',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
            try {
                $stmt = $db->query("SELECT value FROM run_meta WHERE name='request'");
                $request = json_decode((string)$stmt->fetchColumn(),true) ?: [];
                $query = $db->prepare('SELECT value FROM run_meta WHERE name LIKE ? ORDER BY name');
                $query->execute(['winner:%']);
                foreach ($query->fetchAll() as $item) $status['results'][] = json_decode((string)$item['value'],true);
                $status['request'] = $request;
                $periods = $db->query("SELECT value FROM run_meta WHERE name='periods'")->fetchColumn();
                $status['periods'] = json_decode((string)$periods,true) ?: [];
            } finally {
                $db = null;
            }
        }
        b2Respond($status);
    }
    if ($action === 'compare') {
        if (!is_file($runDir . '/results.sqlite')) b2Respond(['status'=>'error','message'=>'Сохранённые кандидаты пока не готовы.'],409);
        $symbol = strtoupper(trim((string)($_GET['symbol'] ?? '')));
        $signalMode = (string)($_GET['signal_mode'] ?? 'exact');
        $directionMode = (string)($_GET['direction_mode'] ?? 'follow');
        $exitMode = (string)($_GET['exit_mode'] ?? 'immediate');
        $sort = (string)($_GET['sort'] ?? 'worst');
        $axis = (string)($_GET['axis'] ?? 'entry');
        $entryId = trim((string)($_GET['entry_id'] ?? ''));
        $exitId = trim((string)($_GET['exit_id'] ?? ''));
        if (!in_array($axis,['entry','exit'],true) || $entryId === '' || $exitId === '') {
            b2Respond(['status'=>'error','message'=>'Некорректные параметры сравнения.'],400);
        }
        $page = max(1,(int)($_GET['page'] ?? 1));
        $perPage = 100;
        $db = new PDO('sqlite:' . $runDir . '/results.sqlite',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
        $requestStmt = $db->query("SELECT value FROM run_meta WHERE name='request'");
        $request = json_decode((string)$requestStmt->fetchColumn(),true) ?: [];
        if (!in_array($symbol, $request['symbols'] ?? [], true)) b2Respond(['status'=>'error','message'=>'Тикер не входит в исходный расчёт.'],400);
        if ($axis === 'entry') {
            $where = 'symbol=? AND exit_mode=? AND exit_id=? AND accepted=1';
            $parameters = [$symbol,$exitMode,$exitId];
        } else {
            $where = 'symbol=? AND signal_mode=? AND direction_mode=? AND entry_id=? AND accepted=1';
            $parameters = [$symbol,$signalMode,$directionMode,$entryId];
        }
        $stmt = $db->prepare("SELECT COUNT(*) FROM candidates WHERE $where");
        $stmt->execute($parameters);
        $total = (int)$stmt->fetchColumn();
        $order = $sort === 'sum' ? 'sum_percent DESC' : 'worst_percent DESC';
        $stmt = $db->prepare("SELECT * FROM candidates WHERE $where ORDER BY $order,id LIMIT ? OFFSET ?");
        $stmt->execute([...$parameters,$perPage,($page-1)*$perPage]);
        $items = [];
        foreach ($stmt->fetchAll() as $row) {
            $row['periods'] = json_decode((string)$row['periods_json'],true);
            $row['parameters'] = json_decode((string)$row['parameters_json'],true);
            unset($row['periods_json'],$row['parameters_json']);
            $items[] = $row;
        }
        b2Respond(['status'=>'ready','axis'=>$axis,'total'=>$total,'page'=>$page,'per_page'=>$perPage,'items'=>$items]);
    }
    b2Respond(['status'=>'error','message'=>'Неизвестное действие.'],400);
} catch (Throwable $error) {
    error_log('Basic Checks 2 data: ' . $error->getMessage());
    b2Respond(['status'=>'error','message'=>$error->getMessage()],500);
}
