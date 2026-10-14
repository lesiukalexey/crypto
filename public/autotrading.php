<?php
declare(strict_types=1);

session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict', 'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off']);
session_start();
$savedAccountMode = $_SESSION['autotrading_account_mode'] ?? 'paper';
$accountMode = is_string($savedAccountMode) && in_array($savedAccountMode, ['paper', 'demo', 'real'], true) ? $savedAccountMode : 'paper';
$requestedMode = (string) ($_GET['mode'] ?? '');
if (in_array($requestedMode, ['paper', 'demo', 'real'], true)) $accountMode = $requestedMode;
header('Cache-Control: no-store');

function h(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function decimalInput(mixed $value, string $label, bool $allowZero = true): string {
    $text = trim((string) $value);
    if (!preg_match('/^\d{1,12}(?:\.\d{1,12})?$/', $text) || (!$allowZero && (float) $text <= 0) || (float) $text > 1000000000) {
        throw new RuntimeException($label . ': укажите корректное положительное число.');
    }
    return $text;
}
function signedDecimalInput(mixed $value, string $label): string {
    $text = trim((string) $value);
    if (!preg_match('/^-?\d{1,12}(?:\.\d{1,24})?$/', $text) || abs((float) $text) > 1000000000) throw new RuntimeException($label . ': неверный формат.');
    return $text;
}
function demoTickerCapabilitySnapshot(array $config): ?array {
    $database = is_array($config['mysql'] ?? null) ? $config['mysql'] : [];
    $passwordName = (string) ($database['password_env'] ?? '');
    $password = $passwordName !== '' ? (getenv($passwordName) ?: '') : '';
    if ($password === '' || !isset($database['host'], $database['port'], $database['database'], $database['user'])) return null;
    try {
        $pdo = new PDO(
            sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $database['host'], $database['port'], $database['database']),
            $database['user'], $password,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC],
        );
        $query = $pdo->prepare('SELECT symbol, demo_available, demo_checked_at_utc FROM ticker_capability_checks WHERE category = ? AND demo_checked_at_utc = (SELECT MAX(demo_checked_at_utc) FROM ticker_capability_checks WHERE category = ?) ORDER BY symbol');
        $category = (string) ($config['category'] ?? 'USDT-FUTURES');
        $query->execute([$category, $category]);
        $available = [];
        $unavailable = [];
        $checkedAt = null;
        foreach ($query->fetchAll() as $row) {
            $symbol = strtoupper((string) ($row['symbol'] ?? ''));
            if ($symbol === '') continue;
            if ((int) ($row['demo_available'] ?? 0) === 1) $available[] = $symbol;
            else $unavailable[] = $symbol;
            $checkedAt ??= (string) ($row['demo_checked_at_utc'] ?? '');
        }
        if ($available === [] && $unavailable === []) return null;
        return ['available' => $available, 'unavailable' => $unavailable, 'checked_at' => $checkedAt];
    } catch (Throwable $error) {
        error_log('Unable to read Demo ticker availability: ' . $error->getMessage());
        return null;
    }
}
function assertDemoSymbolsAvailable(array $config, array $symbols): void {
    $snapshot = demoTickerCapabilitySnapshot($config);
    if (!is_array($snapshot)) throw new RuntimeException('Не удалось подтвердить доступность тикеров в Bitget Demo. Обновите список доступности и повторите запуск.');
    $available = array_fill_keys($snapshot['available'], true);
    $unavailable = array_values(array_unique(array_filter(array_map('strtoupper', $symbols), static fn (string $symbol): bool => $symbol !== '' && !isset($available[$symbol]))));
    if ($unavailable !== []) throw new RuntimeException('Запуск Bitget Demo запрещён: тикеры недоступны или не проверены — ' . implode(', ', $unavailable) . '.');
}
function executablePortfolioStrategies(array $strategies, array $strategyRuns): array {
    $stopping = [];
    foreach ($strategyRuns as $run) {
        if (($run['status'] ?? '') === 'stopping') $stopping[(string) ($run['strategy_id'] ?? '')] = true;
    }
    return array_values(array_filter($strategies, static fn (array $strategy): bool => !empty($strategy['enabled']) || isset($stopping[(string) ($strategy['strategy_id'] ?? '')])));
}
function timeMsLabel(mixed $value): string {
    if (!is_numeric($value) || (int) $value <= 0) return '—';
    return (new DateTimeImmutable('@' . (int) floor((int) $value / 1000)))->setTimezone(new DateTimeZone('Europe/Kyiv'))->format('d.m.Y H:i:s');
}
function autotradingEventTitle(array $event): string {
    $type = (string) ($event['type'] ?? '');
    $payload = is_array($event['payload'] ?? null) ? $event['payload'] : [];
    $code = (string) ($payload['error_code'] ?? $payload['code'] ?? '');
    if ($code === '' && preg_match('/\\bcode\\s+(\\d+)\\b/i', (string) ($payload['message'] ?? ''), $codeMatch)) $code = $codeMatch[1];
    if (str_starts_with($code, 'ORDER_')) return 'Не пройдена проверка размера заявки';
    if ($code !== '') return 'Ошибка Bitget API · код ' . $code;
    return match ($type) {
        'exchange_order_preflight_failed' => 'Ордер не прошёл проверку до отправки',
        'exchange_order_rejected' => 'Bitget отклонил ордер',
        'exchange_order_uncertain' => 'Неизвестно, принял ли Bitget ордер',
        'exchange_runtime_error' => 'Ошибка Bitget или сверки счёта',
        'exchange_reconciliation_mismatch' => 'Данные Bitget не совпали с портфелем',
        'strategy_entry_failed' => 'Вход стратегии отклонён; лимит освобождён после проверки позиции',
        'strategy_removal_requested' => 'Запрошено удаление стратегии из портфеля',
        'market_data_error' => 'Ошибка получения котировок',
        default => $type !== '' ? $type : 'Ошибка исполнителя',
    };
}
function autotradingEventExplanation(array $event): string {
    $payload = is_array($event['payload'] ?? null) ? $event['payload'] : [];
    $code = (string) ($payload['error_code'] ?? $payload['code'] ?? '');
    if ($code === '' && preg_match('/\bcode\s+(\d+)\b/i', (string) ($payload['message'] ?? ''), $codeMatch)) $code = $codeMatch[1];
    if ($code === 'ORDER_SIZE_OUT_OF_RANGE') {
        $requested = (string) ($payload['requested_quantity'] ?? '—');
        $minimum = (string) ($payload['minimum_quantity'] ?? '—');
        $step = (string) ($payload['size_step'] ?? '—');
        $spent = (string) ($payload['requested_notional_usdt'] ?? '—');
        $required = (string) ($payload['estimated_minimum_order_usdt'] ?? '—');
        return "Количество {$requested} меньше минимума {$minimum} или не соответствует шагу {$step}. Сумма заявки около {$spent} USDT; минимальный ориентир по контракту — {$required} USDT.";
    }
    if ($code === '40034') return 'Bitget сообщает, что параметр запроса не существует. Нужны проверка endpoint и состава параметров.';
    if ($code !== '') return 'Bitget вернул ошибку с кодом ' . $code . '.';
    if (($event['type'] ?? '') === 'exchange_order_uncertain') {
        if (array_key_exists('order_submitted', $payload) && !$payload['order_submitted']) return 'Не удалось проверить состояние до отправки. Новая заявка не отправлялась; восстановите связь и повторите после проверки счёта.';
        return 'Ответ потерян или не подтверждён. Сначала проверьте ордер и позиции через сверку; повторная отправка может создать дубликат.';
    }
    if (($event['type'] ?? '') === 'strategy_entry_failed') return 'Первая заявка была отклонена. Стратегия снята с новых входов; при частично открытом хедже исполнитель закроет принятую ногу и только затем удалит запись и её лимит.';
    if (($event['type'] ?? '') === 'strategy_removal_requested') return 'Новые входы остановлены. Исполнитель сверит незавершённые заявки и закроет позицию стратегии; запись и выделенный лимит будут удалены после подтверждения нулевой позиции.';
    if (($payload['reason'] ?? '') === 'ValueError') return 'В старой записи сохранён только тип ошибки без причины. Новые события будут содержать сообщение и параметры проверки.';
    return (string) ($payload['api_message'] ?? $payload['message'] ?? $payload['reason'] ?? 'Исполнитель сохранил ошибку; откройте технические подробности.');
}
function percentageLabel(int $units, int $scale): string {
    $value = number_format($units / $scale, 4, '.', '');
    return rtrim(rtrim($value, '0'), '.') . '%';
}
function entryDescription(array $strategy): string {
    $config = (string) ($strategy['entry_config'] ?? '');
    if ($config === 'immediate:long') return 'сразу открыть лонг на первой котировке';
    if ($config === 'immediate:short') return 'сразу открыть шорт на первой котировке';
    if (!preg_match('/^entry(?::(long|short))?:(\d+):(\d+)(?::(within))?(?::(reverse))?$/', $config, $match)) return $config !== '' ? $config : 'не задано';
    $window = (int) $match[2];
    $threshold = percentageLabel((int) $match[3], 10);
    $within = ($match[4] ?? '') === 'within' || (($match[4] ?? '') === '' && ($strategy['entry_signal_mode'] ?? '') === 'within');
    $reverse = ($match[5] ?? '') === 'reverse' || (($match[5] ?? '') === '' && ($strategy['entry_direction_mode'] ?? '') === 'reverse');
    $scope = ($match[1] ?? '') === 'long' ? 'только при росте' : ((($match[1] ?? '') === 'short') ? 'только при снижении' : 'при росте или снижении');
    $trigger = $within ? 'достижение порога относительно любой из последних ' . $window . ' котировок' : 'пересечение порога относительно котировки ' . $window . ' снимков назад';
    return $trigger . ' на ' . $threshold . '; сигнал ' . $scope . '; вход ' . ($reverse ? 'против' : 'по') . ' движению';
}
function exitDescription(string $config): string {
    if (preg_match('/^fixed:(\d+):(\d+)$/', $config, $match)) return 'тейк-профит ' . percentageLabel((int) $match[1], 100) . ' / стоп-лосс ' . percentageLabel((int) $match[2], 100) . ' от стартового баланса';
    if (preg_match('/^profit:(\d+)$/', $config, $match)) return 'закрыть при прибыли ' . percentageLabel((int) $match[1], 10) . ' от стартового баланса';
    if (preg_match('/^loss:(\d+)$/', $config, $match)) return 'закрыть при убытке ' . percentageLabel((int) $match[1], 10) . ' от стартового баланса';
    return $config !== '' ? $config : 'не задано';
}
function runPython(array $arguments, string $root, string $python): array {
    $environment = array_merge(['PYTHONPATH' => $root], $GLOBALS['autotrading_demo_env'] ?? []);
    foreach (['BITGET_DEMO_ENV_FILE', 'BITGET_DEMO_API_KEY', 'BITGET_DEMO_API_SECRET', 'BITGET_DEMO_API_PASSPHRASE', 'BITGET_LIVE_ENV_FILE', 'BITGET_LIVE_TRADING_ENABLED'] as $name) {
        $value = getenv($name);
        if ($value !== false && $value !== '') $environment[$name] = $value;
    }
    $process = proc_open(array_merge([$python, '-m', 'autotrading'], $arguments), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, $environment);
    if (!is_resource($process)) throw new RuntimeException('Не удалось запустить локальный Python исполнитель.');
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]); fclose($pipes[2]);
    return [proc_close($process), trim((string) $stdout), trim((string) $stderr)];
}

$root = dirname(__DIR__);
$config = json_decode((string) file_get_contents($root . '/config.json'), true, 512, JSON_THROW_ON_ERROR);
$userHome = getenv('HOME') ?: '/home/alex';
$demoEnvFile = getenv('BITGET_DEMO_ENV_FILE') ?: '';
if ($demoEnvFile === '') {
    $localDemoEnv = rtrim($userHome, '/') . '/.ai/home/.local/bitget-autotrading-demo.env';
    if (is_file($localDemoEnv)) $demoEnvFile = $localDemoEnv;
}
$demoEnvironment = [];
if ($demoEnvFile !== '') $demoEnvironment['BITGET_DEMO_ENV_FILE'] = $demoEnvFile;
$liveEnvFile = getenv('BITGET_LIVE_ENV_FILE') ?: '';
if ($liveEnvFile === '') {
    $localLiveEnv = rtrim($userHome, '/') . '/.ai/home/.local/bitget-autotrading-live.env';
    if (is_file($localLiveEnv)) $liveEnvFile = $localLiveEnv;
}
if ($liveEnvFile !== '') $demoEnvironment['BITGET_LIVE_ENV_FILE'] = $liveEnvFile;
$GLOBALS['autotrading_demo_env'] = $demoEnvironment;
$uid = function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();
$runtimeRoot = getenv('BITGET_AUTOTRADING_DIR') ?: '/var/tmp';
$runtime = rtrim($runtimeRoot, '/') . '/bitget-autotrading-' . $uid;
if (!is_dir($runtime) && !mkdir($runtime, 0700, true) && !is_dir($runtime)) throw new RuntimeException('Не удалось создать каталог локального состояния.');
if (is_link($runtime) || fileowner($runtime) !== $uid) throw new RuntimeException('Каталог локального состояния имеет неподходящего владельца.');
chmod($runtime, 0700);
$statePath = $runtime . ($accountMode === 'paper' ? '/state.sqlite' : ($accountMode === 'demo' ? '/state-demo.sqlite' : '/state-live.sqlite'));
$controlPath = $runtime . '/controls-' . $accountMode . '.json';
$riskSettingsPath = $runtime . '/risk-settings.json';
$modeStatePaths = ['paper' => $runtime . '/state.sqlite', 'demo' => $runtime . '/state-demo.sqlite', 'real' => $runtime . '/state-live.sqlite'];
$modePidPaths = [];
foreach ($modeStatePaths as $modeKey => $modeStatePath) {
    $modePidPath = $runtime . '/autotrading-' . $modeKey . '.pid.json';
    if (!is_file($modePidPath) && is_file($runtime . '/paper.pid.json')) {
        $legacyPid = json_decode((string) file_get_contents($runtime . '/paper.pid.json'), true);
        if (is_array($legacyPid) && (string) ($legacyPid['state'] ?? '') === $modeStatePath) $modePidPath = $runtime . '/paper.pid.json';
    }
    $modePidPaths[$modeKey] = $modePidPath;
}
$pidPath = $modePidPaths[$accountMode];
$logPath = $runtime . '/autotrading-' . $accountMode . '.log';
$activeReleasePath = $runtime . '/active-release.json';
$favoritesPath = (getenv('HOME') ?: '/home/alex') . '/.ai/home/.local/bitget-favorites.json';
$releaseDir = $runtime . '/releases';
$python = getenv('BITGET_AUTOTRADING_PYTHON') ?: (is_executable($root . '/.venv/bin/python') ? $root . '/.venv/bin/python' : '/usr/bin/python3');
if (!is_executable($python)) $python = 'python3';

if (!isset($_SESSION['autotrading_csrf'])) $_SESSION['autotrading_csrf'] = bin2hex(random_bytes(32));
$csrf = (string) $_SESSION['autotrading_csrf'];
$message = '';
$messageType = 'info';
$demoReconciliation = is_array($_SESSION['autotrading_demo_status'] ?? null) ? $_SESSION['autotrading_demo_status'] : null;
$isLocal = in_array((string) ($_SERVER['REMOTE_ADDR'] ?? ''), ['127.0.0.1', '::1'], true);
$params = static fn (string $name, string $default = ''): string => trim((string) ($_GET[$name] ?? $default));
$symbol = strtoupper($params('symbol', (string) ($config['symbols'][0] ?? 'BTCUSDT')));
$category = strtoupper($params('category', (string) ($config['category'] ?? 'USDT-FUTURES')));
$startDate = $params('start_date', date('Y-m-d', strtotime('-30 days')));
$endDate = $params('end_date', date('Y-m-d'));
$entryConfig = $params('entry_config', 'entry:10:1');
$entrySignalMode = $params('entry_signal_mode', 'exact');
$entryDirectionMode = $params('entry_direction_mode', 'follow');
$exitConfig = $params('exit_config', 'fixed:10:10');
$martingaleMode = $params('martingale_mode', 'none');
$martingaleTiming = $params('martingale_timing', 'immediate');
$martingaleAttempts = $params('martingale_attempts', '3');
$martingaleTargetPolicy = $params('martingale_target_policy', 'scaled');
$maxChainLoss = $params('max_chain_loss_usdt', '0');
$strategyMaxDayLoss = $params('strategy_max_day_loss_usdt', '0');
$exitConfirmationMode = $params('exit_confirmation_mode', 'immediate');
$exitConfirmationSnapshots = $params('exit_confirmation_snapshots', '0');
$balance = $params('balance', '100');
$feePercent = $params('fee', '0.06');
$slippageBps = $params('slippage_bps', '0');
$includeFunding = $params('include_funding', '1') === '1';
$netResult = $params('net_result_usdt', '0');
$tradeCount = $params('trade_count', '0');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!$isLocal) throw new RuntimeException('Управление paper режимом доступно только с этого компьютера.');
        if (!hash_equals($csrf, (string) ($_POST['csrf'] ?? ''))) throw new RuntimeException('Сессия формы истекла. Обновите страницу и повторите действие.');
        $action = (string) ($_POST['action'] ?? '');
        $argsBase = ['--state', $statePath, '--pid-file', $pidPath, '--control', $controlPath];
        if ($action === 'account-mode') {
            $requestedMode = (string) ($_POST['account_mode'] ?? '');
            if (!in_array($requestedMode, ['paper', 'demo', 'real'], true)) throw new RuntimeException('Выбран неизвестный режим счёта.');
            $_SESSION['autotrading_account_mode'] = $requestedMode;
            $_GET['mode'] = $requestedMode;
            $accountMode = $requestedMode;
            $message = $requestedMode === 'paper'
                ? 'Выбран Paper: запустится виртуальный портфель без ордеров на Bitget.'
                : ($requestedMode === 'demo'
                    ? 'Выбран Bitget Demo: после запуска портфель будет выставлять Demo ордера и сверять их исполнения.'
                    : 'Выбран Bitget Real: запуск требует отдельного переключателя сервера и точного подтверждения каждого запуска.');
            $messageType = $requestedMode === 'paper' ? 'success' : 'warning';
        } elseif ($action === 'add_favorite') {
            [$currentCode, $currentOut] = runPython(array_merge(['status'], $argsBase), $root, $python);
            $currentStatus = $currentCode === 0 ? json_decode($currentOut, true) : null;
            if (is_array($currentStatus) && !empty($currentStatus['service']['running']) && ($currentStatus['service']['mode'] ?? '') !== 'portfolio') throw new RuntimeException('Сейчас работает старый одиночный paper процесс. Остановите его, добавьте избранное и запустите общий портфель.');
            $favoriteId = trim((string) ($_POST['favorite_id'] ?? ''));
            $favorites = is_file($favoritesPath) ? json_decode((string) file_get_contents($favoritesPath), true) : [];
            $favorite = null;
            foreach (is_array($favorites) ? $favorites : [] as $candidate) if (is_array($candidate) && (string) ($candidate['id'] ?? '') === $favoriteId) { $favorite = $candidate; break; }
            if (!is_array($favorite)) throw new RuntimeException('Запись избранного не найдена. Обновите страницу и попробуйте снова.');
            $favoriteParams = is_array($favorite['params'] ?? null) ? $favorite['params'] : [];
            $kind = (string) ($favorite['type'] ?? 'strategy') === 'hedge' ? 'hedge' : 'strategy';
            $symbols = $kind === 'hedge'
                ? [strtoupper((string) ($favoriteParams['long_symbol'] ?? '')), strtoupper((string) ($favoriteParams['short_symbol'] ?? ''))]
                : [strtoupper((string) ($favoriteParams['symbol'] ?? ''))];
            $enabled = array_fill_keys(array_map('strtoupper', $config['symbols'] ?? []), true);
            if (in_array('', $symbols, true) || count(array_unique($symbols)) !== count($symbols) || count(array_filter($symbols, static fn (string $value): bool => isset($enabled[$value]))) !== count($symbols)) throw new RuntimeException('В избранном есть тикер, который выключен или не поддерживается настройками.');
            if ($accountMode === 'demo') assertDemoSymbolsAvailable($config, $symbols);
            $starting = decimalInput($favoriteParams['balance'] ?? '100', 'Стартовый баланс', false);
            $fee = decimalInput($favoriteParams['fee'] ?? '0.06', 'Комиссия');
            $slippage = decimalInput($favoriteParams['slippage_bps'] ?? '0', 'Проскальзывание');
            $martingaleMode = (string) ($favoriteParams['martingale_mode'] ?? 'none');
            $martingaleTiming = (string) ($favoriteParams['martingale_timing'] ?? 'immediate');
            $martingaleAttempts = (int) ($favoriteParams['martingale_attempts'] ?? 3);
            $maxChainLoss = decimalInput($favoriteParams['martingale_max_chain_loss'] ?? '0', 'Максимальный убыток цепочки');
            $maxDayLoss = decimalInput($favoriteParams['martingale_max_day_loss'] ?? $favoriteParams['strategy_max_day_loss_usdt'] ?? '0', 'Максимальный убыток за день');
            $exitConfirmationMode = (string) ($favoriteParams['exit_confirmation_mode'] ?? 'immediate');
            $exitConfirmationSnapshots = (int) ($favoriteParams['exit_confirmation_snapshots'] ?? 0);
            $executorRunning = is_array($currentStatus) && !empty($currentStatus['service']['running']);
            $entry = $kind === 'hedge' ? 'immediate:long' : (string) ($favoriteParams['entry_config'] ?? $favoriteParams['ranking_entry_config'] ?? '');
            $exit = $kind === 'hedge' ? 'fixed:100:100' : (string) ($favoriteParams['exit_config'] ?? '');
            if ($entry === '' || $exit === '') throw new RuntimeException('В избранном отсутствует условие входа или выхода для автоторговли.');
            $releaseId = 'favorite-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(3));
            $release = [
                'schema_version' => 1, 'release_id' => $releaseId, 'created_at_utc' => gmdate('Y-m-d\\TH:i:s\\Z'),
                'market' => ['category' => 'USDT-FUTURES', 'symbols' => $symbols, 'margin_coin' => 'USDT'],
                'strategy' => [
                    'entry_config' => $entry, 'entry_signal_mode' => (string) ($favoriteParams['entry_signal_mode'] ?? 'exact'), 'entry_direction_mode' => (string) ($favoriteParams['entry_direction_mode'] ?? 'follow'),
                    'exit_config' => $exit, 'martingale_mode' => $martingaleMode, 'martingale_timing' => $martingaleTiming, 'martingale_attempts' => $martingaleAttempts, 'martingale_target_policy' => (string) ($favoriteParams['martingale_target_policy'] ?? 'scaled'),
                    'max_chain_loss_usdt' => $maxChainLoss, 'max_day_loss_usdt' => $maxDayLoss, 'exit_confirmation_mode' => $exitConfirmationMode, 'exit_confirmation_snapshots' => $exitConfirmationSnapshots,
                ],
                'execution' => ['starting_balance_usdt' => $starting, 'leverage' => '1', 'taker_fee_rate' => bcdiv($fee, '100', 24), 'slippage_bps' => $slippage, 'include_funding' => (string) ($favoriteParams['include_funding'] ?? '0') === '1'],
                'risk' => ['max_order_notional_usdt' => $starting, 'max_total_exposure_usdt' => $starting, 'max_daily_loss_usdt' => decimalInput((string) max(0.01, (float) $maxDayLoss), 'Дневной лимит', false), 'max_open_positions' => 1],
                'backtest' => ['start_date' => (string) ($favoriteParams['start_day'] ?? $favoriteParams['start_date'] ?? date('Y-m-d', strtotime('-30 days'))), 'end_date' => (string) ($favoriteParams['end_day'] ?? $favoriteParams['end_date'] ?? date('Y-m-d')), 'result_key' => hash('sha256', $favoriteId), 'net_result_usdt' => '0', 'trade_count' => 0],
            ];
            if ($kind === 'hedge') $release['risk']['max_order_notional_usdt'] = $starting;
            if (!is_dir($releaseDir) && !mkdir($releaseDir, 0700, true) && !is_dir($releaseDir)) throw new RuntimeException('Не удалось создать каталог стратегий.');
            if (is_link($releaseDir) || fileowner($releaseDir) !== $uid) throw new RuntimeException('Каталог стратегий имеет неподходящего владельца.');
            $releasePath = $releaseDir . '/' . $releaseId . '.json';
            if (file_put_contents($releasePath, json_encode($release, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n", LOCK_EX) === false) throw new RuntimeException('Не удалось сохранить стратегию автоторговли.');
            chmod($releasePath, 0600);
            [$checkCode, $checkOut, $checkError] = runPython(['paper-check', $releasePath], $root, $python);
            if ($checkCode !== 0) throw new RuntimeException('Параметры избранного не прошли проверку автоторговли: ' . ($checkError ?: $checkOut));
            $addArgs = ['portfolio-add', '--state', $statePath, '--release', $releasePath, '--kind', $kind, '--name', (string) ($favorite['name'] ?? $releaseId)];
            if ($executorRunning) $addArgs[] = '--disabled';
            [$addCode, $addOut, $addError] = runPython($addArgs, $root, $python);
            if ($addCode !== 0) throw new RuntimeException($addError ?: $addOut ?: 'Не удалось добавить избранное в портфель.');
            $portfolioLabel = match ($accountMode) { 'paper' => 'Paper', 'demo' => 'Bitget Demo', default => 'Bitget Real' };
            $message = ($kind === 'hedge' ? 'Хедж-пара' : 'Стратегия') . ' «' . (string) ($favorite['name'] ?? $releaseId) . '» добавлена в портфель ' . $portfolioLabel . ($executorRunning ? ' выключенной: исполнитель работает, новые сделки не откроются. Возобновите её в портфеле.' : '. Исполнитель не запускался.');
            $messageType = 'success';
        } elseif ($action === 'add_hedge') {
            if (!in_array($accountMode, ['paper', 'demo', 'real'], true)) throw new RuntimeException('Выбранную хедж-пару можно добавить в портфель Paper, Demo или Real.');
            [$currentCode, $currentOut] = runPython(array_merge(['status'], $argsBase), $root, $python);
            $currentStatus = $currentCode === 0 ? json_decode($currentOut, true) : null;
            if (!is_array($currentStatus)) throw new RuntimeException('Не удалось проверить состояние портфеля.');
            $executorRunning = !empty($currentStatus['service']['running']);
            $longSymbol = strtoupper(trim((string) ($_POST['hedge_long'] ?? '')));
            $shortSymbol = strtoupper(trim((string) ($_POST['hedge_short'] ?? '')));
            $enabled = array_fill_keys(array_map('strtoupper', $config['symbols'] ?? []), true);
            if ($longSymbol === $shortSymbol || !isset($enabled[$longSymbol], $enabled[$shortSymbol])) throw new RuntimeException('Проверьте выбранные тикеры: лонг и шорт должны быть разными активными тикерами.');
            if ($accountMode === 'demo') assertDemoSymbolsAvailable($config, [$longSymbol, $shortSymbol]);
            $starting = decimalInput($_POST['hedge_balance'] ?? '', 'Стартовый баланс', false);
            $fee = decimalInput($_POST['hedge_fee'] ?? '', 'Комиссия');
            foreach (($currentStatus['portfolio_strategies'] ?? []) as $existingStrategy) {
                if (($existingStrategy['kind'] ?? '') === 'hedge' && array_values($existingStrategy['symbols'] ?? []) === [$longSymbol, $shortSymbol]) {
                    throw new RuntimeException('Эта хедж-пара уже добавлена в портфель. Откройте пункт «Портфель».');
                }
            }
            $releaseId = 'hedge-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(3));
            $metadataDay = date('Y-m-d');
            $release = [
                'schema_version' => 1, 'release_id' => $releaseId, 'created_at_utc' => gmdate('Y-m-d\\TH:i:s\\Z'),
                'market' => ['category' => 'USDT-FUTURES', 'symbols' => [$longSymbol, $shortSymbol], 'margin_coin' => 'USDT'],
                'strategy' => [
                    'entry_config' => 'immediate:long', 'entry_signal_mode' => 'exact', 'entry_direction_mode' => 'follow',
                    'exit_config' => 'fixed:100:100', 'martingale_mode' => 'none', 'martingale_timing' => 'immediate', 'martingale_attempts' => 3, 'martingale_target_policy' => 'scaled',
                    'max_chain_loss_usdt' => '0', 'max_day_loss_usdt' => '0', 'exit_confirmation_mode' => 'immediate', 'exit_confirmation_snapshots' => 0,
                ],
                'execution' => ['starting_balance_usdt' => $starting, 'leverage' => '1', 'taker_fee_rate' => bcdiv($fee, '100', 24), 'slippage_bps' => '0', 'include_funding' => false],
                'risk' => ['max_order_notional_usdt' => $starting, 'max_total_exposure_usdt' => $starting, 'max_daily_loss_usdt' => decimalInput((string) max(0.01, (float) $starting * 0.1), 'Дневной лимит', false), 'max_open_positions' => 1],
                'backtest' => ['start_date' => $metadataDay, 'end_date' => $metadataDay, 'result_key' => hash('sha256', $longSymbol . '|' . $shortSymbol), 'net_result_usdt' => '0', 'trade_count' => 0],
            ];
            if (!is_dir($releaseDir) && !mkdir($releaseDir, 0700, true) && !is_dir($releaseDir)) throw new RuntimeException('Не удалось создать каталог стратегий.');
            if (is_link($releaseDir) || fileowner($releaseDir) !== $uid) throw new RuntimeException('Каталог стратегий имеет неподходящего владельца.');
            $releasePath = $releaseDir . '/' . $releaseId . '.json';
            if (file_put_contents($releasePath, json_encode($release, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n", LOCK_EX) === false) throw new RuntimeException('Не удалось сохранить хедж-пару.');
            chmod($releasePath, 0600);
            [$checkCode, $checkOut, $checkError] = runPython(['paper-check', $releasePath], $root, $python);
            if ($checkCode !== 0) throw new RuntimeException('Хедж-пара не прошла проверку портфеля: ' . ($checkError ?: $checkOut));
            $addArgs = ['portfolio-add', '--state', $statePath, '--release', $releasePath, '--kind', 'hedge', '--name', 'Лонг ' . $longSymbol . ' / шорт ' . $shortSymbol];
            if ($executorRunning) $addArgs[] = '--disabled';
            [$addCode, $addOut, $addError] = runPython($addArgs, $root, $python);
            if ($addCode !== 0) throw new RuntimeException($addError ?: $addOut ?: 'Не удалось добавить хедж-пару в портфель.');
            $portfolioLabel = match ($accountMode) { 'paper' => 'Paper', 'demo' => 'Bitget Demo', default => 'Bitget Real' };
            $message = 'Хедж-пара «Лонг ' . $longSymbol . ' / шорт ' . $shortSymbol . '» добавлена в портфель ' . $portfolioLabel . ($executorRunning ? ' выключенной: исполнитель работает, новые сделки не откроются. Для запуска возобновите стратегию в портфеле.' : '. Запуск не выполнялся.');
            $messageType = 'success';
        } elseif ($action === 'publish') {
            [$currentCode, $currentOut] = runPython(array_merge(['status'], $argsBase), $root, $python);
            $currentStatus = $currentCode === 0 ? json_decode($currentOut, true) : null;
            if (is_array($currentStatus) && !empty($currentStatus['service']['running']) && ($currentStatus['service']['mode'] ?? '') !== 'portfolio') throw new RuntimeException('Сейчас работает старый одиночный paper процесс. Остановите его, затем добавьте стратегию в общий портфель.');
            $strategyValues = [
                'category' => (string) ($_POST['category'] ?? ''), 'symbol' => strtoupper((string) ($_POST['symbol'] ?? '')),
                'start_date' => (string) ($_POST['start_date'] ?? ''), 'end_date' => (string) ($_POST['end_date'] ?? ''),
                'entry_config' => (string) ($_POST['entry_config'] ?? ''), 'entry_signal_mode' => (string) ($_POST['entry_signal_mode'] ?? ''),
                'entry_direction_mode' => (string) ($_POST['entry_direction_mode'] ?? ''), 'exit_config' => (string) ($_POST['exit_config'] ?? ''),
                'martingale_mode' => (string) ($_POST['martingale_mode'] ?? ''), 'martingale_timing' => (string) ($_POST['martingale_timing'] ?? ''),
                'martingale_attempts' => (int) ($_POST['martingale_attempts'] ?? 0), 'martingale_target_policy' => (string) ($_POST['martingale_target_policy'] ?? ''),
                'max_chain_loss_usdt' => (string) ($_POST['max_chain_loss_usdt'] ?? ''), 'max_day_loss_usdt' => (string) ($_POST['strategy_max_day_loss_usdt'] ?? ''),
                'exit_confirmation_mode' => (string) ($_POST['exit_confirmation_mode'] ?? ''), 'exit_confirmation_snapshots' => (int) ($_POST['exit_confirmation_snapshots'] ?? -1),
                'starting_balance_usdt' => decimalInput($_POST['balance'] ?? '', 'Стартовый баланс', false),
                'taker_fee_rate' => bcdiv(decimalInput($_POST['fee'] ?? '', 'Комиссия'), '100', 24),
                'slippage_bps' => decimalInput($_POST['slippage_bps'] ?? '', 'Проскальзывание'),
                'include_funding' => (string) ($_POST['include_funding'] ?? '') === '1',
                'max_order_notional_usdt' => decimalInput($_POST['max_order_notional_usdt'] ?? '', 'Максимальный размер ордера', false),
                'max_total_exposure_usdt' => decimalInput($_POST['max_total_exposure_usdt'] ?? '', 'Максимальная общая позиция', false),
                'max_daily_loss_usdt' => decimalInput($_POST['max_daily_loss_usdt'] ?? '', 'Максимальный дневной убыток', false),
                'max_open_positions' => (int) ($_POST['max_open_positions'] ?? 0),
                'net_result_usdt' => signedDecimalInput($_POST['net_result_usdt'] ?? '0', 'Результат бэктеста'),
                'trade_count' => (int) ($_POST['trade_count'] ?? -1),
            ];
            if ($strategyValues['include_funding']) throw new RuntimeException('В текущей paper версии funding не моделируется. Вернитесь в «Базовые проверки», отключите funding и откройте эту страницу снова.');
            if ($strategyValues['martingale_mode'] !== 'none') throw new RuntimeException('В текущей paper версии мартингейл не поддерживается. Выберите «Без мартингейла» и откройте эту страницу снова.');
            if ($strategyValues['category'] !== 'USDT-FUTURES' || !preg_match('/^[A-Z0-9]{2,40}$/', $strategyValues['symbol'])) throw new RuntimeException('Paper v1 сейчас поддерживает линейные USDT futures. Выбранный рынок или тикер не поддерживается.');
            $created = gmdate('Y-m-d\TH:i:s\Z');
            $releaseId = 'paper-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(3));
            $release = [
                'schema_version' => 1, 'release_id' => $releaseId, 'created_at_utc' => $created,
                'market' => ['category' => $category, 'symbols' => [$strategyValues['symbol']], 'margin_coin' => str_contains($category, 'USDT') ? 'USDT' : 'USDC'],
                'strategy' => [
                    'entry_config' => $strategyValues['entry_config'], 'entry_signal_mode' => $strategyValues['entry_signal_mode'], 'entry_direction_mode' => $strategyValues['entry_direction_mode'],
                    'exit_config' => $strategyValues['exit_config'], 'martingale_mode' => $strategyValues['martingale_mode'], 'martingale_timing' => $strategyValues['martingale_timing'],
                    'martingale_attempts' => $strategyValues['martingale_attempts'], 'martingale_target_policy' => $strategyValues['martingale_target_policy'],
                    'max_chain_loss_usdt' => $strategyValues['max_chain_loss_usdt'], 'max_day_loss_usdt' => $strategyValues['max_day_loss_usdt'],
                    'exit_confirmation_mode' => $strategyValues['exit_confirmation_mode'], 'exit_confirmation_snapshots' => $strategyValues['exit_confirmation_snapshots'],
                ],
                'execution' => ['starting_balance_usdt' => $strategyValues['starting_balance_usdt'], 'leverage' => '1', 'taker_fee_rate' => $strategyValues['taker_fee_rate'], 'slippage_bps' => $strategyValues['slippage_bps'], 'include_funding' => false],
                'risk' => ['max_order_notional_usdt' => $strategyValues['max_order_notional_usdt'], 'max_total_exposure_usdt' => $strategyValues['max_total_exposure_usdt'], 'max_daily_loss_usdt' => $strategyValues['max_daily_loss_usdt'], 'max_open_positions' => $strategyValues['max_open_positions']],
                'backtest' => ['start_date' => $strategyValues['start_date'], 'end_date' => $strategyValues['end_date'], 'result_key' => hash('sha256', json_encode([$strategyValues['symbol'], $strategyValues['entry_config'], $strategyValues['exit_config'], $strategyValues['start_date'], $strategyValues['end_date']])), 'net_result_usdt' => $strategyValues['net_result_usdt'], 'trade_count' => $strategyValues['trade_count']],
            ];
            if ($strategyValues['max_open_positions'] < 1 || $strategyValues['max_open_positions'] > 100 || $strategyValues['trade_count'] < 0) throw new RuntimeException('Проверьте ограничения риска и число сделок.');
            if ($strategyValues['max_order_notional_usdt'] > $strategyValues['max_total_exposure_usdt']) throw new RuntimeException('Лимит одного ордера не может быть больше общего лимита позиции.');
            if ($strategyValues['start_date'] > $strategyValues['end_date']) throw new RuntimeException('Начальная дата должна быть раньше конечной.');
            if (!is_dir($releaseDir) && !mkdir($releaseDir, 0700, true) && !is_dir($releaseDir)) throw new RuntimeException('Не удалось создать каталог стратегий.');
            if (is_link($releaseDir) || fileowner($releaseDir) !== $uid) throw new RuntimeException('Каталог стратегий имеет неподходящего владельца.');
            $releaseJson = json_encode($release, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
            $releasePath = $releaseDir . '/' . $releaseId . '.json';
            if (file_put_contents($releasePath, $releaseJson, LOCK_EX) === false) throw new RuntimeException('Не удалось сохранить версию стратегии.');
            chmod($releasePath, 0600);
            [$validateCode, $validateOut, $validateError] = runPython(['paper-check', $releasePath], $root, $python);
            if ($validateCode !== 0) { unlink($releasePath); throw new RuntimeException('Стратегия не прошла проверку: ' . ($validateError ?: $validateOut)); }
            [$addCode, $addOut, $addError] = runPython(['portfolio-add', '--state', $statePath, '--release', $releasePath, '--kind', 'strategy', '--name', $releaseId], $root, $python);
            if ($addCode !== 0) throw new RuntimeException($addError ?: $addOut ?: 'Не удалось добавить стратегию в общий портфель.');
            $activeTemporary = $runtime . '/.active-release.' . bin2hex(random_bytes(4)) . '.tmp';
            if (!copy($releasePath, $activeTemporary) || !rename($activeTemporary, $activeReleasePath)) {
                @unlink($activeTemporary);
                throw new RuntimeException('Не удалось выбрать опубликованную версию.');
            }
            chmod($activeReleasePath, 0600);
            $message = 'Стратегия добавлена в общий paper портфель: ' . $releaseId . '.';
            $messageType = 'success';
        } elseif ($action === 'demo-reconcile') {
            $demoStatePath = $runtime . '/state-demo.sqlite';
            [$demoStatusCode, $demoStatusOut] = runPython(['status', '--state', $demoStatePath, '--pid-file', $modePidPaths['demo']], $root, $python);
            $demoLocalStatus = $demoStatusCode === 0 ? json_decode($demoStatusOut, true) : null;
            if (is_array($demoLocalStatus) && !empty($demoLocalStatus['service']['running']) && ($demoLocalStatus['service']['execution_mode'] ?? '') === 'demo') throw new RuntimeException('Для ручной сверки сначала остановите Demo исполнитель.');
            $demoSymbols = [(string) ($_POST['symbol'] ?? $symbol)];
            foreach (($demoLocalStatus['portfolio_strategies'] ?? []) as $strategy) foreach (($strategy['symbols'] ?? []) as $strategySymbol) $demoSymbols[] = (string) $strategySymbol;
            $demoSymbols = array_values(array_unique(array_map('strtoupper', $demoSymbols)));
            foreach ($demoSymbols as $demoSymbol) if (!preg_match('/^[A-Z0-9]{2,40}$/', $demoSymbol)) throw new RuntimeException('В портфеле есть некорректный тикер для проверки Demo.');
            unset($_SESSION['autotrading_demo_status']);
            [$code, $out, $err] = runPython(['demo-reconcile', '--symbols', implode(',', $demoSymbols), '--state', $demoStatePath], $root, $python);
            $report = json_decode($out, true);
            if (!is_array($report) || ($report['mode'] ?? null) !== 'demo') throw new RuntimeException('Не удалось получить результат проверки Demo API: ' . ($err ?: $out ?: 'пустой ответ исполнителя.'));
            $_SESSION['autotrading_demo_status'] = $report;
            $demoReconciliation = $report;
            $message = ($report['matched'] ?? false) ? 'Demo сверка завершена: расхождений нет.' : 'Demo сверка завершена: обнаружено расхождение или ошибка подключения.';
            $messageType = ($report['matched'] ?? false) ? 'success' : 'warning';
        } elseif ($action === 'start') {
            $enableLive = false;
            if ($accountMode === 'real') {
                if ((string) ($_POST['live_acknowledgement'] ?? '') !== 'I_ACKNOWLEDGE_LIVE_ORDERS') throw new RuntimeException('Для запуска Real введите подтверждение I_ACKNOWLEDGE_LIVE_ORDERS.');
                if (getenv('BITGET_LIVE_TRADING_ENABLED') !== '1') throw new RuntimeException('В окружении исполнителя не включён BITGET_LIVE_TRADING_ENABLED=1.');
                $enableLive = true;
            }
            if ($accountMode !== 'paper') {
                [$copyCode, $copyOut, $copyErr] = runPython(['portfolio-copy', '--state', $statePath, '--source-state', $runtime . '/state.sqlite'], $root, $python);
                if ($copyCode !== 0) throw new RuntimeException($copyErr ?: $copyOut ?: 'Не удалось синхронизировать выбранные стратегии с общим портфелем.');
            }
            [$listCode, $listOut] = runPython(['status', '--state', $statePath], $root, $python);
            $portfolioState = $listCode === 0 ? json_decode($listOut, true) : null;
            if ((!is_array($portfolioState) || empty($portfolioState['portfolio_strategies'])) && is_file($activeReleasePath)) {
                [$addCode, $addOut, $addErr] = runPython(['portfolio-add', '--state', $statePath, '--release', $activeReleasePath, '--kind', 'strategy', '--name', (string) ($published['release_id'] ?? 'Ранее опубликованная стратегия')], $root, $python);
                if ($addCode !== 0) throw new RuntimeException($addErr ?: $addOut ?: 'Не удалось перенести опубликованную стратегию в общий портфель.');
            }
            if ($accountMode === 'demo') {
                $demoPortfolioSymbols = [];
                $executableStrategies = executablePortfolioStrategies($portfolioState['portfolio_strategies'] ?? [], $portfolioState['strategy_runs'] ?? []);
                foreach ($executableStrategies as $portfolioStrategy) {
                    foreach (($portfolioStrategy['symbols'] ?? []) as $portfolioSymbol) $demoPortfolioSymbols[] = strtoupper((string) $portfolioSymbol);
                }
                if ($demoPortfolioSymbols === [] && empty($portfolioState['portfolio_strategies']) && is_array($published)) $demoPortfolioSymbols = array_map('strtoupper', array_map('strval', $published['market']['symbols'] ?? []));
                if ($demoPortfolioSymbols === []) throw new RuntimeException('Запуск Bitget Demo невозможен: в портфеле нет включённых стратегий. Добавьте или возобновите стратегию.');
                assertDemoSymbolsAvailable($config, $demoPortfolioSymbols);
            }
            $startArgs = ['portfolio-start', '--state', $statePath, '--control', $controlPath, '--pid-file', $pidPath, '--log', $logPath, '--risk-settings', $riskSettingsPath, '--interval', '30', '--stale-seconds', '90', '--mode', $accountMode];
            if ($enableLive) $startArgs[] = '--enable-live';
            [$code, $out, $err] = runPython($startArgs, $root, $python);
            if ($code !== 0) throw new RuntimeException($err ?: $out ?: 'Служба не запустилась.');
            $message = $accountMode === 'paper'
                ? 'Общий Paper портфель запущен. Стратегии используют единый виртуальный баланс.'
                : ($accountMode === 'demo' ? 'Общий портфель запущен на Bitget Demo. Проверяйте журнал ордеров и сверку.' : 'Общий портфель запущен на Bitget Real после явного подтверждения.');
            $messageType = 'success';
        } elseif ($action === 'stop') {
            [$code, $out, $err] = runPython(['service-stop', '--state', $statePath, '--pid-file', $pidPath], $root, $python);
            if ($code !== 0) throw new RuntimeException($err ?: $out ?: 'Не удалось остановить службу.');
            $message = 'Исполнитель остановлен. Состояние и позиции сохранены для выбранного режима.'; $messageType = 'success';
        } elseif ($action === 'remove-strategy') {
            if ($accountMode !== 'demo') throw new RuntimeException('Удаление из этого списка доступно в тестовой автоторговле Bitget Demo.');
            $strategyId = strtolower(trim((string) ($_POST['strategy_id'] ?? '')));
            if (!preg_match('/^[a-f0-9]{64}$/', $strategyId)) throw new RuntimeException('Некорректный идентификатор стратегии.');
            [$healthCode, $healthOut] = runPython(['status', '--state', $statePath, '--pid-file', $pidPath], $root, $python);
            $health = $healthCode === 0 ? json_decode($healthOut, true) : null;
            if (!is_array($health)) throw new RuntimeException('Не удалось проверить состояние исполнителя.');
            $removeArgs = ['portfolio-remove', '--state', $statePath, '--strategy-id', $strategyId];
            if (!empty($health['service']['running'])) $removeArgs[] = '--defer';
            [$code, $out, $err] = runPython($removeArgs, $root, $python);
            if ($code !== 0) throw new RuntimeException($err ?: $out ?: 'Не удалось удалить стратегию из портфеля.');
            $removeResult = json_decode($out, true);
            $message = !empty($removeResult['requested'])
                ? 'Удаление запрошено. Стратегия будет удалена после сверки заявок и закрытия её позиций; лимит вернётся в общий баланс.'
                : 'Стратегия удалена из тестового портфеля. Её выделенный лимит возвращён в общий баланс.';
            $messageType = 'success';
        } elseif ($action === 'stop-strategy' || $action === 'resume-strategy') {
            $strategyId = strtolower(trim((string) ($_POST['strategy_id'] ?? '')));
            if (!preg_match('/^[a-f0-9]{64}$/', $strategyId)) throw new RuntimeException('Некорректный идентификатор стратегии.');
            if ($action === 'stop-strategy') {
                [$healthCode, $healthOut] = runPython(['status', '--state', $statePath, '--pid-file', $pidPath], $root, $python);
                $health = $healthCode === 0 ? json_decode($healthOut, true) : null;
                if (!is_array($health) || empty($health['service']['running'])) throw new RuntimeException('Запустите исполнитель, чтобы он мог закрыть позиции стратегии.');
                [$code, $out, $err] = runPython(['strategy-stop', '--state', $statePath, '--strategy-id', $strategyId], $root, $python);
                if ($code !== 0) throw new RuntimeException($err ?: $out ?: 'Не удалось остановить стратегию.');
                $message = 'Стратегия остановлена: новые входы запрещены, её позиции закрываются по свежим котировкам.';
            } else {
                [$code, $out, $err] = runPython(['strategy-resume', '--state', $statePath, '--strategy-id', $strategyId], $root, $python);
                if ($code !== 0) throw new RuntimeException($err ?: $out ?: 'Не удалось возобновить стратегию.');
                $message = 'Стратегия добавлена в активный портфель и получит новую сессию риска.';
            }
            $messageType = 'success';
        } elseif ($action === 'pause' || $action === 'resume' || $action === 'close') {
            [$statusCode, $statusOut] = runPython(array_merge(['status'], $argsBase), $root, $python);
            $health = $statusCode === 0 ? json_decode($statusOut, true) : null;
            if (!is_array($health) || !($health['service']['running'] ?? false)) throw new RuntimeException('Сначала запустите paper исполнителя.');
            $controlAction = $action === 'pause' ? 'pause_entries' : ($action === 'resume' ? 'resume_entries' : 'close_positions');
            [$code, $out, $err] = runPython(['control', '--control', $controlPath, $controlAction], $root, $python);
            if ($code !== 0) throw new RuntimeException($err ?: $out ?: 'Не удалось изменить управление.');
            $message = ['pause' => 'Новые входы приостановлены; открытая позиция остаётся под управлением.', 'resume' => 'Новые входы снова разрешены.', 'close' => 'Позиции будут закрыты по ближайшим свежим котировкам; новые входы приостановлены.'][$action];
            $messageType = 'success';
        } else throw new RuntimeException('Неизвестное действие.');
    } catch (Throwable $exception) {
        $message = $exception->getMessage();
        $messageType = 'error';
    }
}

if (in_array($messageType, ['success', 'warning'], true)) {
    $_SESSION['autotrading_flash'] = [$message, $messageType];
    $query = http_build_query($_GET);
    header('Location: autotrading.php' . ($query === '' ? '' : '?' . $query), true, 303);
    exit;
}
if ($message === '' && is_array($_SESSION['autotrading_flash'] ?? null)) {
    [$message, $messageType] = $_SESSION['autotrading_flash'];
    unset($_SESSION['autotrading_flash']);
}

$status = ['mode' => 'paper', 'initialized' => false, 'open_positions' => 0, 'positions' => [], 'unrealized_pnl_usdt' => '0', 'recent_trades' => [], 'recent_events' => [], 'trades' => 0, 'service' => ['running' => false], 'controls' => ['entries_paused' => false, 'close_positions' => false]];
[$statusCode, $statusOut] = runPython(['status', '--state', $statePath, '--pid-file', $pidPath, '--control', $controlPath], $root, $python);
if ($statusCode === 0 && is_array($decoded = json_decode($statusOut, true))) $status = array_merge($status, $decoded);
$latestExecutionError = null;
foreach (($status['recent_events'] ?? []) as $executionEvent) {
    $eventType = (string) ($executionEvent['type'] ?? '');
    if (str_contains($eventType, 'error') || str_contains($eventType, 'failed') || str_contains($eventType, 'rejected') || str_contains($eventType, 'uncertain') || str_contains($eventType, 'mismatch')) {
        $latestExecutionError = $executionEvent;
        break;
    }
}
$demoTickerSnapshot = $accountMode === 'demo' ? demoTickerCapabilitySnapshot($config) : null;
$demoStartBlockedSymbols = [];
if ($accountMode === 'demo') {
    $executableStrategies = executablePortfolioStrategies($status['portfolio_strategies'] ?? [], $status['strategy_runs'] ?? []);
    foreach ($executableStrategies as $portfolioStrategy) foreach (($portfolioStrategy['symbols'] ?? []) as $portfolioSymbol) $demoStartBlockedSymbols[] = strtoupper((string) $portfolioSymbol);
    if ($demoStartBlockedSymbols === [] && empty($status['portfolio_strategies']) && is_array($published)) $demoStartBlockedSymbols = array_map('strtoupper', array_map('strval', $published['market']['symbols'] ?? []));
    if (is_array($demoTickerSnapshot)) {
        $demoAvailableLookup = array_fill_keys($demoTickerSnapshot['available'], true);
        $demoStartBlockedSymbols = array_values(array_unique(array_filter($demoStartBlockedSymbols, static fn (string $ticker): bool => !isset($demoAvailableLookup[$ticker]))));
    } elseif ($demoStartBlockedSymbols !== []) {
        $demoStartBlockedSymbols[] = 'нет актуального списка доступности';
    }
}
$portfolioNameById = [];
foreach (($status['portfolio_strategies'] ?? []) as $portfolioStrategy) $portfolioNameById[(string) ($portfolioStrategy['strategy_id'] ?? '')] = (string) ($portfolioStrategy['name'] ?? '');
$historyStrategies = is_array($status['portfolio_strategies'] ?? null) ? $status['portfolio_strategies'] : [];
$historyStrategyId = (string) ($_GET['history_strategy_id'] ?? ($historyStrategies[0]['strategy_id'] ?? ''));
if ($historyStrategyId !== '' && !isset($portfolioNameById[$historyStrategyId])) $historyStrategyId = (string) ($historyStrategies[0]['strategy_id'] ?? '');
$historySymbols = [];
foreach ($historyStrategies as $historyStrategy) {
    if ($historyStrategyId === '' || (string) ($historyStrategy['strategy_id'] ?? '') === $historyStrategyId) {
        $historySymbols = array_merge($historySymbols, array_map('strval', $historyStrategy['symbols'] ?? []));
    }
}
if ($historySymbols === []) $historySymbols = array_map('strval', $config['symbols'] ?? [$symbol]);
$historySymbols = array_values(array_unique(array_filter($historySymbols, static fn (string $item): bool => preg_match('/^[A-Z0-9]{2,40}$/', $item) === 1)));
sort($historySymbols, SORT_STRING);
$historySymbol = strtoupper((string) ($_GET['history_symbol'] ?? ($historySymbols[0] ?? $symbol)));
if (!in_array($historySymbol, $historySymbols, true)) $historySymbol = $historySymbols[0] ?? $symbol;
$historyHedgeSymbols = [];
foreach ($historyStrategies as $historyStrategy) {
    if ($historyStrategyId !== '' && (string) ($historyStrategy['strategy_id'] ?? '') === $historyStrategyId && ($historyStrategy['kind'] ?? '') === 'hedge') {
        $historyHedgeSymbols = array_slice(array_values(array_unique(array_map('strval', $historyStrategy['symbols'] ?? []))), 0, 2);
        break;
    }
}
$historyToday = new DateTimeImmutable('today', new DateTimeZone('Europe/Kyiv'));
$historyStartDate = (string) ($_GET['history_start_date'] ?? $historyToday->modify('-30 days')->format('Y-m-d'));
$historyEndDate = (string) ($_GET['history_end_date'] ?? $historyToday->format('Y-m-d'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $historyStartDate)) $historyStartDate = $historyToday->modify('-30 days')->format('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $historyEndDate)) $historyEndDate = $historyToday->format('Y-m-d');
$displayMode = !empty($status['service']['running']) ? (string) ($status['service']['execution_mode'] ?? $accountMode) : $accountMode;
$activeRunByStrategy = [];
foreach (($status['strategy_runs'] ?? []) as $strategyRun) if (in_array((string) ($strategyRun['status'] ?? ''), ['running', 'stopping'], true)) $activeRunByStrategy[(string) ($strategyRun['strategy_id'] ?? '')] = $strategyRun;
$formatStopDisplay = static fn (float $value, int $precision = 4): string => rtrim(rtrim(number_format($value, $precision, '.', ' '), '0'), '.');
$portfolioStopInfo = [];
foreach ($activeRunByStrategy as $strategyId => $run) {
    $peakEquity = (float) ($run['peak_equity_usdt'] ?? 0);
    $hasCurrentEquity = isset($run['last_equity_usdt']) && is_numeric($run['last_equity_usdt']);
    $currentEquity = $hasCurrentEquity ? (float) $run['last_equity_usdt'] : null;
    $minimumPercent = (float) ($run['minimum_equity_percent'] ?? 75);
    if ($peakEquity <= 0 || $minimumPercent <= 0) continue;
    $floorEquity = $peakEquity * $minimumPercent / 100;
    $lossRoom = $currentEquity === null ? null : max(0, $currentEquity - $floorEquity);
    $stopTriggered = (($run['status'] ?? '') === 'stopping' && ($run['stop_reason'] ?? '') === 'loss_threshold')
        || ($currentEquity !== null && $currentEquity <= $floorEquity);
    $prices = [];
    foreach ($stopTriggered ? [] : ($status['positions'] ?? []) as $position) {
        if ((string) ($position['strategy_id'] ?? '') !== $strategyId) continue;
        $mark = isset($position['mark_price']) ? (float) $position['mark_price'] : 0;
        $sensitivity = isset($position['equity_price_sensitivity_usdt']) ? (float) $position['equity_price_sensitivity_usdt'] : 0;
        $trigger = null;
        $triggerMessage = 'нет свежей котировки для расчёта';
        $quoteTimestamp = (int) ($position['quote_timestamp_ms'] ?? 0);
        $quoteIsFresh = $quoteTimestamp > 0 && (int) floor(microtime(true) * 1000) - $quoteTimestamp <= 90_000;
        if ($mark > 0 && $sensitivity > 0 && $lossRoom !== null && $quoteIsFresh) {
            $trigger = ($position['side'] ?? '') === 'short' ? $mark + $lossRoom / $sensitivity : $mark - $lossRoom / $sensitivity;
            if ($trigger <= 0) {
                $trigger = null;
                $triggerMessage = 'не достигается только движением этой ноги';
            } else {
                $triggerMessage = '';
            }
        }
        $prices[] = [
            'symbol' => (string) ($position['symbol'] ?? '—'),
            'leg' => (string) ($position['leg'] ?? 'single'),
            'side' => (string) ($position['side'] ?? ''),
            'trigger' => $trigger,
            'trigger_message' => $triggerMessage,
        ];
    }
    $portfolioStopInfo[$strategyId] = [
        'peak' => $peakEquity,
        'current' => $currentEquity,
        'floor' => $floorEquity,
        'minimum_percent' => $minimumPercent,
        'triggered' => $stopTriggered,
        'prices' => $prices,
    ];
}
$positionStrategyIds = array_fill_keys(array_map(static fn (array $position): string => (string) ($position['strategy_id'] ?? ''), $status['positions'] ?? []), true);
$published = is_file($activeReleasePath) ? json_decode((string) file_get_contents($activeReleasePath), true) : null;
$savedFavorites = is_file($favoritesPath) ? json_decode((string) file_get_contents($favoritesPath), true) : [];
$selectedFavoriteId = trim((string) ($_GET['favorite_id'] ?? ''));
$selectedFavorite = null;
foreach (is_array($savedFavorites) ? $savedFavorites : [] as $candidate) if (is_array($candidate) && (string) ($candidate['id'] ?? '') === $selectedFavoriteId) { $selectedFavorite = $candidate; break; }
$hedgePreviewLong = strtoupper(trim((string) ($_GET['hedge_long'] ?? '')));
$hedgePreviewShort = strtoupper(trim((string) ($_GET['hedge_short'] ?? '')));
$hedgePreviewStartDay = (string) ($_GET['hedge_start_day'] ?? '');
$hedgePreviewEndDay = (string) ($_GET['hedge_end_day'] ?? '');
$hedgePreviewBalance = (string) ($_GET['hedge_balance'] ?? '');
$hedgePreviewFee = (string) ($_GET['hedge_fee'] ?? '');
$enabledHedgeSymbols = array_fill_keys(array_map('strtoupper', $config['symbols'] ?? []), true);
$hasHedgePreview = in_array($accountMode, ['paper', 'demo', 'real'], true) && $hedgePreviewLong !== '' && $hedgePreviewShort !== '' && $hedgePreviewLong !== $hedgePreviewShort && isset($enabledHedgeSymbols[$hedgePreviewLong], $enabledHedgeSymbols[$hedgePreviewShort]);
$autotradingView = (string) ($_GET['view'] ?? 'setup');
$allowedAutotradingViews = ['setup', 'portfolio', 'state', 'deals', 'runs', 'events'];
if (!in_array($autotradingView, $allowedAutotradingViews, true)) $autotradingView = 'setup';
$hedgeQuery = $hasHedgePreview ? [
    'hedge_long' => $hedgePreviewLong, 'hedge_short' => $hedgePreviewShort,
    'hedge_start_day' => $hedgePreviewStartDay, 'hedge_end_day' => $hedgePreviewEndDay,
    'hedge_balance' => $hedgePreviewBalance, 'hedge_fee' => $hedgePreviewFee,
] : [];
$autotradingContextQuery = $hedgeQuery;
foreach (['favorite_id', 'category', 'symbol', 'start_date', 'end_date', 'entry_config', 'entry_signal_mode', 'entry_direction_mode', 'exit_config', 'martingale_mode', 'martingale_timing', 'martingale_attempts', 'martingale_target_policy', 'max_chain_loss_usdt', 'strategy_max_day_loss_usdt', 'exit_confirmation_mode', 'exit_confirmation_snapshots', 'balance', 'fee', 'slippage_bps', 'include_funding', 'net_result_usdt', 'trade_count'] as $contextKey) {
    if (isset($_GET[$contextKey]) && is_scalar($_GET[$contextKey])) $autotradingContextQuery[$contextKey] = (string) $_GET[$contextKey];
}
$autotradingViewUrl = static fn (string $view): string => 'autotrading.php?' . http_build_query(array_merge(['mode' => $accountMode, 'view' => $view], $autotradingContextQuery));
$autotradingModeViewUrl = static fn (string $mode): string => 'autotrading.php?' . http_build_query(array_merge(['mode' => $mode, 'view' => 'setup'], $autotradingContextQuery));
$selectedStrategyPresent = isset($_GET['symbol']);
$paperStrategySupported = $category === 'USDT-FUTURES' && !$includeFunding && $martingaleMode === 'none';
$hedgePreviewCanAdd = $hasHedgePreview
    && preg_match('/^\d{1,12}(?:\.\d{1,12})?$/', $hedgePreviewBalance) === 1 && (float) $hedgePreviewBalance > 0 && (float) $hedgePreviewBalance <= 1000000000
    && preg_match('/^\d{1,12}(?:\.\d{1,12})?$/', $hedgePreviewFee) === 1 && (float) $hedgePreviewFee >= 0 && (float) $hedgePreviewFee <= 5;
$selectedHedgeInPortfolio = false;
if ($hasHedgePreview) foreach (($status['portfolio_strategies'] ?? []) as $portfolioStrategy) {
    if (($portfolioStrategy['kind'] ?? '') === 'hedge' && array_values($portfolioStrategy['symbols'] ?? []) === [$hedgePreviewLong, $hedgePreviewShort]) {
        $selectedHedgeInPortfolio = true;
        break;
    }
}
$postHidden = [
    'category' => $category, 'symbol' => $symbol, 'start_date' => $startDate, 'end_date' => $endDate,
    'entry_config' => $entryConfig, 'entry_signal_mode' => $entrySignalMode, 'entry_direction_mode' => $entryDirectionMode,
    'exit_config' => $exitConfig, 'martingale_mode' => $martingaleMode, 'martingale_timing' => $martingaleTiming,
    'martingale_attempts' => $martingaleAttempts, 'martingale_target_policy' => $martingaleTargetPolicy,
    'max_chain_loss_usdt' => $maxChainLoss, 'strategy_max_day_loss_usdt' => $strategyMaxDayLoss,
    'exit_confirmation_mode' => $exitConfirmationMode, 'exit_confirmation_snapshots' => $exitConfirmationSnapshots,
    'balance' => $balance, 'fee' => $feePercent, 'slippage_bps' => $slippageBps, 'include_funding' => $includeFunding ? '1' : '0',
    'net_result_usdt' => $netResult, 'trade_count' => $tradeCount,
];
?>
<!doctype html>
<html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Автоторговля · Bitget</title>
<style>
:root{color-scheme:dark;--bg:#0b1020;--panel:#131a2d;--muted:#8995ad;--text:#e9eefb;--line:#273149}*{box-sizing:border-box}body{margin:0;background:radial-gradient(ellipse at 15% 0%,#1b2850 0,transparent 45%),var(--bg);color:var(--text);font:15px/1.5 system-ui,-apple-system,Segoe UI,sans-serif}.wrap{max-width:1300px;margin:auto;padding:30px 20px}h1{margin:12px 0;font-size:clamp(26px,4vw,38px)}h2{font-size:18px;margin:0 0 12px}.nav{display:flex;gap:6px;flex-wrap:wrap;margin:18px 0;padding:5px;border:1px solid var(--line);border-radius:12px;background:#0c1222;width:max-content;max-width:100%}.nav a{color:#b7c1d5;text-decoration:none;padding:8px 12px;border-radius:8px}.nav a:hover,.nav a[aria-current=page]{background:#263968;color:#fff}.panel{background:var(--panel);border:1px solid var(--line);border-radius:15px;padding:18px;margin:14px 0}.status{padding:11px 14px;border:1px solid var(--line);border-radius:10px}.status.error{color:#ffb3bf;border-color:#733b49}.status.success{color:#a8f0cf;border-color:#28654f}.muted,.note{color:var(--muted);font-size:13px}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(165px,1fr));gap:12px}.stat{padding:12px;border:1px solid var(--line);border-radius:10px;background:#0c1222}.stat span{display:block;color:var(--muted);font-size:12px}.stat strong{font-size:19px;font-variant-numeric:tabular-nums}.fields{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px}.field{display:grid;gap:5px;color:var(--muted);font-size:13px}.field input{width:100%;padding:9px 10px;border:1px solid #34415e;border-radius:8px;color:var(--text);background:#0c1222;font:inherit}button{padding:10px 14px;border:0;border-radius:8px;background:#607df4;color:white;font:inherit;font-weight:700;cursor:pointer}button.secondary{background:#34415e}button.danger{background:#8f3949}button:disabled{opacity:.5;cursor:not-allowed}.actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:14px}.table-wrap{overflow:auto}table{width:100%;border-collapse:collapse;min-width:650px;font-size:13px}td,th{text-align:left;padding:8px 9px;border-bottom:1px solid var(--line);white-space:nowrap}th{color:var(--muted)}.inline{display:inline}.pill{display:inline-block;padding:3px 9px;border:1px solid #34415e;border-radius:99px;color:#b7c1d5}.pill.on{border-color:#28654f;color:#a8f0cf}.warning{color:#ffe0a1}.empty{color:var(--muted)}@media(max-width:600px){.wrap{padding:18px 12px}.panel{padding:14px}.nav{width:100%}.nav a{flex:1;text-align:center}.actions button{flex:1}}
 .published-summary{margin:16px 0;padding:14px;border:1px solid #3c527e;border-radius:12px;background:#101a30}.published-summary .stat strong,.selected-summary .stat strong{display:block;font-size:14px;line-height:1.45;overflow-wrap:anywhere}.subnav{margin-top:-8px}
.history-filters{display:flex;align-items:end;gap:12px;flex-wrap:wrap}.history-filters .field{min-width:150px}.history-filters select,.history-filters input{width:100%;padding:9px 10px;border:1px solid #34415e;border-radius:8px;color:var(--text);background:#0c1222;font:inherit}.history-chart{display:block;width:100%;height:auto;min-height:260px;margin-top:12px;overflow:visible}.history-chart-modes{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px}.history-chart-modes button[aria-pressed="true"]{background:#263968}.history-chart-legend{display:flex;gap:16px;flex-wrap:wrap;margin-top:8px;color:#b7c1d5;font-size:13px}.history-chart-legend span{display:flex;align-items:center;gap:6px}.history-chart-legend i{display:inline-block;width:10px;height:10px;border-radius:50%}.history-chart text{fill:#8995ad;font:12px system-ui,sans-serif}.history-chart .grid-line{stroke:#273149;stroke-width:1}.history-chart .price-line{fill:none;stroke:#86a8ff;stroke-width:2}.history-chart .comparison-line{fill:none;stroke-width:2;stroke-linejoin:round;stroke-linecap:round}.history-chart .paper-trade{stroke:#f0bd65;fill:#f0bd65}.history-chart .actual-trade{stroke:#42d6a4;fill:#42d6a4}.history-chart .trade-link{fill:none;stroke-width:1.5;opacity:.6}.history-chart .trade-entry{stroke-width:2}.history-chart .trade-hover-line{stroke:#d9c6ff;stroke-width:1.5;stroke-dasharray:5 4;pointer-events:none}.history-chart .trade-crosshair-line{stroke:#d9c6ff;stroke-width:1;stroke-dasharray:4 4;pointer-events:none}.history-chart .trade-crosshair-label{font-size:11px;font-weight:800;fill:#e9eefb;paint-order:stroke;stroke:#0b1020;stroke-width:4;stroke-linejoin:round;pointer-events:none}.history-chart .trade-hover-arrow,.history-chart .trade-hover-price{font-weight:800;paint-order:stroke;stroke:#0b1020;stroke-width:4;stroke-linejoin:round;pointer-events:none}.history-chart .trade-hover-price{font-size:11px;fill:#e9eefb}.history-chart .trade-face{fill:#0b1020;stroke-width:1.8;pointer-events:none}.history-chart .trade-face-part{fill:none;stroke-width:1.5;stroke-linecap:round;pointer-events:none}.history-table{min-width:1000px;font-variant-numeric:tabular-nums}.history-table th{padding:11px 10px;color:#aab5cb;font-size:11px;text-transform:uppercase;letter-spacing:.04em;background:#0d1425;position:sticky;top:0}.history-table td{text-align:right;padding:11px 10px}.history-table th:first-child,.history-table td:first-child{text-align:left}.history-trade-positive td{background:#123126;color:#b9f2dc}.history-trade-negative td{background:#351e2a;color:#ffd0d5}.history-trade-open-positive td{background:#06291d;color:#83e8b8}.history-trade-open-negative td{background:#290810;color:#ff929d}.history-trade-flat td{background:#222b3d}.history-trade-positive td:first-child,.history-trade-open-positive td:first-child{box-shadow:inset 3px 0 #48d6a4}.history-trade-negative td:first-child,.history-trade-open-negative td:first-child{box-shadow:inset 3px 0 #ff7788}.history-trade-row{cursor:crosshair}.history-trade-row:hover td,.history-trade-row:focus td{filter:brightness(1.22)}.history-summary{display:grid;grid-template-columns:repeat(auto-fit,minmax(145px,1fr));gap:10px;margin:14px 0}.history-summary .stat strong{font-size:16px}.history-status{min-height:22px;color:var(--muted)}
</style></head><body><main class="wrap">
<div class="muted">Bitget · локальный исполнитель</div><h1>Автоторговля</h1>
<nav class="nav" aria-label="Разделы"><a href="index.php">Главная</a><a href="basic-checks.php">Базовые проверки</a><a href="hedging.php">Хеджирование</a><a href="favorites.php">Избранное</a><a href="settings.php">Настройки</a><a href="<?= h($autotradingModeViewUrl('paper')) ?>" <?= $accountMode === 'paper' ? 'aria-current="page"' : '' ?>>Автоторговля (Paper)</a><a href="<?= h($autotradingModeViewUrl('demo')) ?>" <?= $accountMode === 'demo' ? 'aria-current="page"' : '' ?>>Автоторговля (Тест)</a><a href="<?= h($autotradingModeViewUrl('real')) ?>" <?= $accountMode === 'real' ? 'aria-current="page"' : '' ?>>Автоторговля (реал)</a><a href="autotrading-pi.php">Raspberry Pi</a></nav>
<nav class="nav subnav" aria-label="Подразделы автоторговли">
<?php foreach (['setup' => 'Подготовка', 'portfolio' => 'Портфель', 'state' => 'Состояние исполнителя', 'deals' => 'История сделок', 'runs' => 'Запуски', 'events' => 'События'] as $viewKey => $viewLabel): ?><a href="<?= h($autotradingViewUrl($viewKey)) ?>" <?= $autotradingView === $viewKey ? 'aria-current="page"' : '' ?>><?= h($viewLabel) ?></a><?php endforeach; ?>
</nav>
<?php if ($message !== ''): ?><p class="status <?= h($messageType) ?>" role="status"><?= h($message) ?></p><?php endif; ?>
<?php if (!$isLocal): ?><p class="status warning">Просмотр доступен, но управление работает только при открытии страницы с этого компьютера.</p><?php endif; ?>
<?php if ($autotradingView === 'setup'): ?>
<?php if ($hasHedgePreview): ?><section class="panel selected-summary" aria-label="Параметры выбранной хедж-пары"><h2>Параметры из хеджирования</h2><div class="grid">
<div class="stat"><span>Лонг</span><strong><?= h($hedgePreviewLong) ?></strong></div><div class="stat"><span>Шорт</span><strong><?= h($hedgePreviewShort) ?></strong></div>
<div class="stat"><span>Стартовый баланс</span><strong><?= h($hedgePreviewBalance ?: '—') ?> USDT</strong></div><div class="stat"><span>Комиссия за сторону</span><strong><?= h($hedgePreviewFee ?: '—') ?>%</strong></div>
</div>
<?php if (!$selectedHedgeInPortfolio): ?><?php if (!empty($status['service']['running'])): ?><p class="note">Исполнитель сейчас работает. Пара будет добавлена выключенной и не начнёт торговлю, пока вы отдельно не нажмёте «Возобновить» в портфеле.</p><?php else: ?><p class="note">После запуска откроется лонг <?= h($hedgePreviewLong) ?> и шорт <?= h($hedgePreviewShort) ?>. Сначала добавьте пару в портфель; запуск — отдельным действием.</p><?php endif; ?><?php if (!$hedgePreviewCanAdd): ?><p class="status warning">Проверьте баланс и комиссию на странице хеджирования.</p><?php endif; ?><form method="post"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="add_hedge"><input type="hidden" name="mode" value="<?= h($accountMode) ?>"><input type="hidden" name="hedge_long" value="<?= h($hedgePreviewLong) ?>"><input type="hidden" name="hedge_short" value="<?= h($hedgePreviewShort) ?>"><input type="hidden" name="hedge_balance" value="<?= h($hedgePreviewBalance) ?>"><input type="hidden" name="hedge_fee" value="<?= h($hedgePreviewFee) ?>"><button <?= !$isLocal || !$hedgePreviewCanAdd ? 'disabled' : '' ?>>Добавить выбранную пару в портфель</button></form>
<?php else: ?><p class="status success">Эта пара уже добавлена в портфель <?= $accountMode === 'demo' ? 'Bitget Demo' : 'Bitget Real' ?>. При запуске откроется лонг <?= h($hedgePreviewLong) ?> и шорт <?= h($hedgePreviewShort) ?>.</p><?php endif; ?></section>
<?php elseif ($selectedStrategyPresent): ?><section class="panel selected-summary"><h2>Выбрано в «Базовых проверках»</h2><div class="grid"><div class="stat"><span>Тикер</span><strong><?= h($symbol) ?></strong></div><div class="stat"><span>Условие входа</span><strong><?= h(entryDescription(['entry_config' => $entryConfig, 'entry_signal_mode' => $entrySignalMode, 'entry_direction_mode' => $entryDirectionMode])) ?></strong></div><div class="stat"><span>Условие выхода</span><strong><?= h(exitDescription($exitConfig)) ?></strong></div><div class="stat"><span>Период</span><strong><?= h($startDate) ?> — <?= h($endDate) ?></strong></div></div></section>
<?php else: ?><section class="panel"><h2>Подготовка запуска</h2><p class="empty">Выберите конфигурацию в «Базовых проверках» или пару в «Хеджировании».</p><a href="hedging.php">Открыть хеджирование</a></section><?php endif; ?>
<?php endif; ?>
<?php if ($autotradingView === 'deals'): ?>
<section class="panel"><h2>Открытые позиции сейчас</h2><div class="grid"><div class="stat"><span>Открыто ног</span><strong><?= h((string) ($status['open_positions'] ?? 0)) ?></strong></div><div class="stat"><span>Общий плавающий результат</span><strong><?= h((string) ($status['unrealized_pnl_usdt'] ?? '0')) ?> USDT</strong></div></div>
<?php if (($status['positions'] ?? []) === []): ?><p class="empty">Открытых позиций нет. Ниже отображаются только уже закрытые сделки.</p><?php else: ?><div class="table-wrap"><table><thead><tr><th>Стратегия</th><th>Нога</th><th>Тикер</th><th>Сторона</th><th>Количество</th><th>Цена входа</th><th>Плавающий PnL · USDT</th><th>Котировка · Киев</th></tr></thead><tbody><?php foreach ($status['positions'] as $position): ?><tr><td><?= h($portfolioNameById[(string) ($position['strategy_id'] ?? '')] ?? 'Старая стратегия') ?></td><td><?= h((string) ($position['leg'] ?? 'single')) ?></td><td><?= h((string) ($position['symbol'] ?? '—')) ?></td><td><?= h((string) ($position['side'] ?? '—')) ?></td><td><?= h((string) ($position['quantity'] ?? '—')) ?></td><td><?= h((string) ($position['entry_price'] ?? '—')) ?></td><td><?= h((string) ($position['unrealized_pnl_usdt'] ?? '—')) ?></td><td><?= h(timeMsLabel($position['quote_timestamp_ms'] ?? null)) ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?><p class="note"><?= !empty($status['service']['running']) ? 'Страница обновляется раз в минуту; исполнитель обновляет котировки независимо.' : 'Исполнитель остановлен; показано последнее локальное состояние.' ?></p></section>
<?php endif; ?>
<?php if ($autotradingView === 'deals' && in_array($accountMode, ['paper', 'demo', 'real'], true) && $isLocal): ?>
<section class="panel" id="autotrading-history" data-mode="<?= h($accountMode) ?>" data-symbol="<?= h($historySymbol) ?>" data-hedge-symbols="<?= h(json_encode($historyHedgeSymbols, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)) ?>" data-strategy-id="<?= h($historyStrategyId) ?>" data-start-date="<?= h($historyStartDate) ?>" data-end-date="<?= h($historyEndDate) ?>">
<h2>Сделки · <?= $accountMode === 'paper' ? 'Paper' : ($accountMode === 'demo' ? 'Bitget Demo и локальный Paper' : 'Bitget Real') ?></h2>
<?php if ($accountMode === 'demo'): ?><p class="note">Сравниваются закрытые сделки одного релиза стратегии по порядку их открытия. Paper показывает расчётные исполнения, Demo — фактические средние цены Bitget и результат после биржевых комиссий.</p><?php elseif ($accountMode === 'paper'): ?><p class="note">Показаны виртуальные сделки Paper и сохранённые публичные котировки Bitget. Ордера на биржу не отправляются.</p><?php else: ?><p class="note">Показаны закрытые сделки Bitget Real с фактическими средними ценами исполнений и результатом после биржевых комиссий.</p><?php endif; ?>
<form class="history-filters" method="get" action="autotrading.php">
<input type="hidden" name="mode" value="<?= h($accountMode) ?>"><?php foreach ($autotradingContextQuery as $name => $value): ?><input type="hidden" name="<?= h($name) ?>" value="<?= h((string) $value) ?>"><?php endforeach; ?>
<input type="hidden" name="view" value="deals">
<label class="field">Стратегия<select name="history_strategy_id" id="history-strategy-select" onchange="this.form.requestSubmit()"><option value="">Все стратегии</option><?php foreach ($historyStrategies as $historyStrategy): $historyId = (string) ($historyStrategy['strategy_id'] ?? ''); ?><option value="<?= h($historyId) ?>" <?= $historyId === $historyStrategyId ? 'selected' : '' ?>><?= h((string) ($historyStrategy['name'] ?? $historyId)) ?></option><?php endforeach; ?></select></label>
<label class="field">Торговая пара<select name="history_symbol"><?php foreach ($historySymbols as $historyOption): ?><option value="<?= h($historyOption) ?>" <?= $historyOption === $historySymbol ? 'selected' : '' ?>><?= h($historyOption) ?></option><?php endforeach; ?></select></label>
<label class="field">С даты<input type="date" name="history_start_date" value="<?= h($historyStartDate) ?>" required></label>
<label class="field">По дату<input type="date" name="history_end_date" value="<?= h($historyEndDate) ?>" required></label>
<button type="submit">Показать сделки</button>
</form>
<p class="history-status" id="history-status" role="status" aria-live="polite">Загружаю историю сделок…</p>
<div class="history-summary" id="history-summary"></div>
<p class="note" id="history-chart-note">Голубая линия — котировки Bitget; <?= $accountMode === 'paper' ? 'жёлтые маркеры — виртуальные исполнения Paper.' : ($accountMode === 'demo' ? 'зелёные маркеры — исполнения Demo, жёлтые — локального Paper.' : 'зелёные маркеры — исполнения Real.') ?></p>
<div class="history-chart-modes" id="history-chart-modes" role="group" aria-label="Режим графика" hidden><button type="button" data-chart-mode="ticker" aria-pressed="true">Один тикер</button><button type="button" data-chart-mode="comparison" aria-pressed="false">Лонг / шорт</button></div><div class="history-chart-legend" id="history-chart-legend" hidden></div><p class="note" id="history-chart-mode-note"></p>
<div class="table-wrap"><svg class="history-chart" id="history-chart" viewBox="0 0 1100 360" role="img" aria-label="График котировок и закрытых сделок"></svg></div>
<h3><?= $accountMode === 'demo' ? 'Сопоставление закрытых сделок' : ($accountMode === 'paper' ? 'Закрытые сделки Paper' : 'Закрытые сделки Bitget Real') ?></h3>
<p class="note" id="history-comparison-note"></p>
<script type="application/json" id="history-open-positions"><?= json_encode(array_values($status['positions'] ?? []), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) ?></script>
<div class="table-wrap" id="history-trades"></div>
</section>
<?php endif; ?>
<?php if ($autotradingView === 'setup'): ?><section class="panel"><h2>Режим счёта</h2><div class="actions" role="group" aria-label="Выбор режима счёта">
<?php $modeOptions = $accountMode === 'paper' ? ['paper' => 'Paper · локальная симуляция'] : ['demo' => 'Bitget Demo', 'real' => 'Bitget Real']; foreach ($modeOptions as $modeKey => $modeLabel): ?><form method="post"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="account-mode"><input type="hidden" name="account_mode" value="<?= h($modeKey) ?>"><button class="<?= $accountMode === $modeKey ? '' : 'secondary' ?>" aria-pressed="<?= $accountMode === $modeKey ? 'true' : 'false' ?>" <?= !$isLocal ? 'disabled' : '' ?>><?= h($modeLabel) ?><?= $accountMode === $modeKey ? ' · выбран' : '' ?></button></form><?php endforeach; ?>
</div>
<?php if ($accountMode === 'paper'): ?><p class="note">Paper использует виртуальные позиции и публичные котировки; ордера на Bitget не отправляются.</p>
<?php elseif ($accountMode === 'demo'): ?><p class="note">Demo отправляет ордера только в Bitget Demo с paptrading: 1. Перед запуском проверьте, что у выбранного аккаунта нет посторонних позиций и ордеров по портфельным тикерам.</p>
<?php if (is_array($demoTickerSnapshot)): ?><p class="status success">Доступно тикеров: <?= count($demoTickerSnapshot['available']) ?> из <?= count($demoTickerSnapshot['available']) + count($demoTickerSnapshot['unavailable']) ?> · проверка: <?= h((string) ($demoTickerSnapshot['checked_at'] ?? '—')) ?> UTC</p><p class="note"><strong>Можно использовать в Demo:</strong> <?= h(implode(', ', $demoTickerSnapshot['available'])) ?></p><?php if ($demoTickerSnapshot['unavailable'] !== []): ?><details><summary>Недоступны в Demo (<?= count($demoTickerSnapshot['unavailable']) ?>)</summary><p class="note"><?= h(implode(', ', $demoTickerSnapshot['unavailable'])) ?></p></details><?php endif; ?><?php else: ?><p class="status warning">Не удалось загрузить список доступности Demo. Запуск будет заблокирован, пока доступность тикеров нельзя подтвердить.</p><?php endif; ?>
<?php else: ?><p class="status warning">Real отправляет реальные USDT futures ордера. Запуск требует производственный API ключ с правом торговли, BITGET_LIVE_TRADING_ENABLED=1 и ручное подтверждение запуска.</p><?php endif; ?></section><?php endif; ?>
<?php if ($autotradingView === 'setup' && is_array($selectedFavorite)): $favoriteParams = is_array($selectedFavorite['params'] ?? null) ? $selectedFavorite['params'] : []; $favoriteName = (string) ($selectedFavorite['name'] ?? 'Избранное'); $favoriteKind = (string) ($selectedFavorite['type'] ?? '') === 'hedge' ? 'Хедж-пара' : 'Стратегия'; $favoriteTicker = $favoriteKind === 'Хедж-пара' ? 'Лонг ' . (string) ($favoriteParams['long_symbol'] ?? '—') . ' / шорт ' . (string) ($favoriteParams['short_symbol'] ?? '—') : (string) ($favoriteParams['symbol'] ?? '—'); ?>
<section class="panel"><h2>Добавить запись из избранного</h2><p><strong><?= h($favoriteName) ?></strong> · <?= h($favoriteKind) ?> · <?= h($favoriteTicker) ?></p><p class="note">В выбранный портфель попадут сохранённые параметры стратегии. Добавление не запускает остановленный исполнитель. Если портфель уже работает, запись добавится выключенной, чтобы не открывать сделки без отдельного возобновления.</p><form method="post"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="add_favorite"><input type="hidden" name="favorite_id" value="<?= h($selectedFavoriteId) ?>"><button <?= !$isLocal ? 'disabled' : '' ?>>Добавить в портфель <?= h(['paper' => 'Paper', 'demo' => 'Bitget Demo', 'real' => 'Bitget Real'][$accountMode] ?? 'автоторговли') ?></button></form></section>
<?php endif; ?>
<?php if ($autotradingView === 'state'): ?><section class="panel"><h2>Состояние исполнителя</h2><div class="grid">
<div class="stat"><span>Процесс</span><strong><?= ($status['service']['running'] ?? false) ? (($status['service']['mode'] ?? '') === 'portfolio' ? 'Портфель работает' : 'Старая одиночная служба') : 'Остановлен' ?></strong></div>
<div class="stat"><span>Фактический режим</span><strong><?= h(['paper' => 'Paper', 'demo' => 'Bitget Demo', 'live' => 'Bitget Real', 'real' => 'Bitget Real'][$displayMode] ?? 'неизвестен') ?></strong></div><div class="stat"><span>Общий лимит портфеля</span><strong><?= h((string) ($status['initial_balance_usdt'] ?? ($published['execution']['starting_balance_usdt'] ?? '—'))) ?> USDT</strong></div>
<div class="stat"><span>Реализованный результат</span><strong><?= h((string) ($status['realized_pnl_usdt'] ?? '0')) ?> USDT</strong></div><div class="stat"><span>Плавающий результат</span><strong><?= h((string) ($status['unrealized_pnl_usdt'] ?? '0')) ?> USDT</strong></div><div class="stat"><span>Закрытые сделки</span><strong><?= h((string) ($status['trades'] ?? 0)) ?></strong></div>
</div><p class="note">PID <?= h((string) ($status['service']['pid'] ?? '—')) ?> · открытых позиций: <?= h((string) ($status['open_positions'] ?? 0)) ?></p>
<?php if (is_array($latestExecutionError)): ?><p class="status warning"><strong><?= h(autotradingEventTitle($latestExecutionError)) ?> · <?= h((string) ($latestExecutionError['symbol'] ?? '—')) ?> · <?= h(timeMsLabel($latestExecutionError['timestamp_ms'] ?? null)) ?></strong><br><?= h(autotradingEventExplanation($latestExecutionError)) ?> · <a href="<?= h($autotradingViewUrl('events')) ?>">Все события и технические данные</a></p><?php endif; ?>
<div class="actions"><form method="post"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="stop"><button class="secondary" <?= !$isLocal || !($status['service']['running'] ?? false) ? 'disabled' : '' ?>>Остановить процесс</button></form><form method="post"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="<?= ($status['controls']['entries_paused'] ?? false) ? 'resume' : 'pause' ?>"><button class="secondary" <?= !$isLocal || !($status['service']['running'] ?? false) ? 'disabled' : '' ?>><?= ($status['controls']['entries_paused'] ?? false) ? 'Возобновить новые входы' : 'Приостановить новые входы' ?></button></form><form method="post" onsubmit="return confirm('Отправить ордера для закрытия всех открытых позиций выбранного режима?')"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="close"><button class="danger" <?= !$isLocal || !($status['service']['running'] ?? false) || ($status['open_positions'] ?? 0) < 1 ? 'disabled' : '' ?>><?= $accountMode === 'paper' ? 'Закрыть paper позиции' : 'Закрыть позиции на Bitget' ?></button></form></div>
<p class="note">Остановка сохраняет позиции и журнал. Для Demo/Real закрытие проходит биржевым ордером с последующей сверкой.</p></section>
<section class="panel"><h2>Открытые позиции</h2><?php if (($status['positions'] ?? []) === []): ?><p class="empty">Открытых позиций нет.</p><?php else: ?><div class="table-wrap"><table><thead><tr><th>Стратегия</th><th>Тикер</th><th>Сторона</th><th>Количество</th><th>Вход</th><th>Плавающий PnL · USDT</th><th>Последняя котировка · Киев</th></tr></thead><tbody><?php foreach ($status['positions'] as $position): ?><tr><td><?= h($portfolioNameById[(string) ($position['strategy_id'] ?? '')] ?? 'Старая стратегия') ?><?= ($position['leg'] ?? 'single') !== 'single' ? ' · ' . h((string) $position['leg']) : '' ?></td><td><?= h((string) $position['symbol']) ?></td><td><?= h((string) $position['side']) ?></td><td><?= h((string) $position['quantity']) ?></td><td><?= h((string) $position['entry_price']) ?></td><td><?= h((string) ($position['unrealized_pnl_usdt'] ?? '—')) ?></td><td><?= h(timeMsLabel($position['quote_timestamp_ms'] ?? null)) ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></section><?php endif; ?>

<?php if ($autotradingView === 'portfolio'): ?><section class="panel"><h2>Стратегии портфеля</h2><?php if (($status['portfolio_strategies'] ?? []) === []): ?><p class="empty">Портфель пока пуст. Выберите пару или стратегию в разделе «Подготовка».</p><?php else: ?><div class="table-wrap"><table><thead><tr><th>Стратегия</th><th>Тип</th><th>Выделенный бюджет</th><th>Стоп-уровень и цены</th><th>Состояние</th><th>Тикеры</th><th>Управление</th></tr></thead><tbody><?php foreach ($status['portfolio_strategies'] as $portfolioStrategy): $strategyId = (string) ($portfolioStrategy['strategy_id'] ?? ''); $run = $activeRunByStrategy[$strategyId] ?? null; ?><tr><td><?= h((string) ($portfolioStrategy['name'] ?? $strategyId)) ?></td><td><?= ($portfolioStrategy['kind'] ?? '') === 'hedge' ? 'Хедж' : 'Стратегия' ?></td><td><?= h((string) ($run['allocation_usdt'] ?? $portfolioStrategy['allocation_usdt'] ?? '—')) ?> USDT</td><td><?php $stopInfo = $portfolioStopInfo[$strategyId] ?? null; if ($stopInfo !== null): ?><strong>Стоп капитала: <?= h($formatStopDisplay($stopInfo['floor'])) ?> USDT</strong><br><small><?= h($formatStopDisplay($stopInfo['minimum_percent'], 2)) ?>% от пика <?= h($formatStopDisplay($stopInfo['peak'])) ?> USDT<?= $stopInfo['current'] !== null ? '; сейчас ' . h($formatStopDisplay($stopInfo['current'])) . ' USDT' : '' ?>.</small><?php if ($stopInfo['triggered']): ?><br><small>Порог достигнут: закрываются все позиции этой стратегии.</small><?php elseif ($stopInfo['prices'] === []): ?><br><small>Открытых позиций пока нет.</small><?php else: ?><?php foreach ($stopInfo['prices'] as $stopPrice): ?><br><small><?= h($stopPrice['symbol']) ?><?= $stopPrice['leg'] !== 'single' ? ' · нога ' . h($stopPrice['leg']) : '' ?> · <?= h($stopPrice['side'] === 'short' ? 'шорт Ask' : 'лонг Bid') ?>: <?= $stopPrice['trigger'] === null ? h($stopPrice['trigger_message']) : '≈ ' . h($formatStopDisplay($stopPrice['trigger'], 8)) . ' USDT' ?></small><?php endforeach; ?><?php endif; ?><?php else: ?>—<?php endif; ?></td><td><?= ($run['status'] ?? '') === 'stopping' ? 'Закрывает позиции и останавливается' : (!empty($portfolioStrategy['enabled']) ? 'В работе' : 'Остановлена') ?></td><td><?= h(implode(', ', array_map('strval', $portfolioStrategy['symbols'] ?? []))) ?></td><td><?php if (($run['status'] ?? '') === 'running'): ?><form class="inline" method="post" onsubmit="return confirm('Закрыть позиции и остановить только эту стратегию?')"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="stop-strategy"><input type="hidden" name="strategy_id" value="<?= h($strategyId) ?>"><button class="danger" <?= !$isLocal || (empty($status['service']['running']) && ($status['open_positions'] ?? 0) > 0) ? 'disabled' : '' ?>>Остановить</button></form><?php elseif (empty($portfolioStrategy['enabled']) && $run === null): ?><form class="inline" method="post"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="resume-strategy"><input type="hidden" name="strategy_id" value="<?= h($strategyId) ?>"><button class="secondary" <?= !$isLocal ? 'disabled' : '' ?>>Возобновить</button></form><?php else: ?>—<?php endif; ?><?php if ($accountMode === 'demo'): ?><form class="inline" method="post" onsubmit="return confirm('Удалить стратегию из портфеля? Если она работает, сначала будут сверены заявки и закрыты её позиции.')"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="remove-strategy"><input type="hidden" name="strategy_id" value="<?= h($strategyId) ?>"><button class="secondary" <?= !$isLocal || isset($positionStrategyIds[$strategyId]) ? 'disabled' : '' ?><?= isset($positionStrategyIds[$strategyId]) ? ' title="Сначала остановите стратегию и закройте её позиции"' : '' ?>>Удалить</button></form><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div><p class="note">Порог учитывает капитал, реализованный результат и плавающий PnL стратегии. Цена каждой ноги рассчитана при текущих ценах остальных тикеров; при достижении порога закрываются все позиции этой стратегии.</p><?php endif; ?></section><?php endif; ?>

<?php if ($autotradingView === 'setup'): ?>
<?php if (is_array($published) && !$hasHedgePreview): ?><section class="panel published-summary" aria-label="Последняя добавленная стратегия"><h2>Текущая конфигурация</h2><div class="grid"><div class="stat"><span>Тикеры</span><strong><?= h(implode(', ', array_map('strval', $published['market']['symbols'] ?? [])) ?: '—') ?></strong></div><div class="stat"><span>Условие входа</span><strong><?= h(entryDescription($published['strategy'] ?? [])) ?></strong></div><div class="stat"><span>Условие выхода</span><strong><?= h(exitDescription((string) ($published['strategy']['exit_config'] ?? ''))) ?></strong></div><div class="stat"><span>Период</span><strong><?= h((string) ($published['backtest']['start_date'] ?? '—')) ?> — <?= h((string) ($published['backtest']['end_date'] ?? '—')) ?></strong></div><div class="stat"><span>Стартовый баланс</span><strong><?= h((string) ($published['execution']['starting_balance_usdt'] ?? '—')) ?> USDT</strong></div></div></section><?php endif; ?>
<section class="panel"><h2>Запуск</h2><?php if ($accountMode === 'demo' && $demoStartBlockedSymbols !== []): ?><p class="status warning">Запуск Demo заблокирован: <?= h(implode(', ', $demoStartBlockedSymbols)) ?>.</p><?php endif; ?><?php if ($hasHedgePreview && !$selectedHedgeInPortfolio): ?><p class="note">Сначала добавьте выбранную пару в портфель выше. Ничего не запускается автоматически.</p><?php else: ?><form method="post"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="start"><?php if ($accountMode === 'real'): ?><label class="field">Подтвердите реальный запуск<input name="live_acknowledgement" autocomplete="off" required pattern="I_ACKNOWLEDGE_LIVE_ORDERS" placeholder="I_ACKNOWLEDGE_LIVE_ORDERS"></label><?php endif; ?><button <?= !$isLocal || ($hasHedgePreview ? !$selectedHedgeInPortfolio : (empty($status['portfolio_strategies']) && !is_array($published))) || ($accountMode === 'demo' && $demoStartBlockedSymbols !== []) || ($status['service']['running'] ?? false) ? 'disabled' : '' ?>><?= ($status['service']['running'] ?? false) ? 'Портфель уже запущен' : ($accountMode === 'paper' ? 'Запустить Paper портфель' : ($accountMode === 'demo' ? 'Запустить Bitget Demo' : 'Запустить Bitget Real')) ?></button></form><?php endif; ?></section>
<?php endif; ?>

<?php if ($autotradingView === 'events' && $accountMode === 'demo'): ?><section class="panel"><h2>Сверка Bitget Demo</h2>
<p class="note">Проверка читает Demo аккаунт и сравнивает его с сохранёнными Demo позициями и ордерами. Она не отправляет и не закрывает ордера. Для ручной сверки остановите Demo исполнитель.</p>
<form method="post"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="demo-reconcile"><input type="hidden" name="symbol" value="<?= h($symbol) ?>"><button class="secondary" <?= !$isLocal || (($status['service']['running'] ?? false) && ($status['service']['execution_mode'] ?? '') === 'demo') ? 'disabled' : '' ?>>Проверить Demo аккаунт и портфель</button></form>
<?php if (is_array($demoReconciliation)): ?>
<p class="status <?= !empty($demoReconciliation['matched']) ? 'success' : 'warning' ?>" role="status">
<?= !empty($demoReconciliation['matched']) ? 'Совпадает' : 'Нужно проверить' ?> · проверено <?= h((string) ($demoReconciliation['checked_at_utc'] ?? '—')) ?> UTC
<?php if (isset($demoReconciliation['pending_orders'])): ?> · позиций: <?= h((string) count($demoReconciliation['positions'] ?? [])) ?> · открытых ордеров: <?= h((string) $demoReconciliation['pending_orders']) ?> · fills: <?= h((string) ($demoReconciliation['fills'] ?? 0)) ?><?php endif; ?>
</p>
<?php if (!empty($demoReconciliation['error'])): ?><p class="status warning"><?= h((string) $demoReconciliation['error']) ?></p><?php endif; ?>
<?php foreach (($demoReconciliation['discrepancies'] ?? []) as $discrepancy): ?><p class="status warning"><?= h((string) $discrepancy) ?></p><?php endforeach; ?>
<?php if (($demoReconciliation['positions'] ?? []) !== []): ?><div class="table-wrap"><table><thead><tr><th>Тикер</th><th>Сторона</th><th>Количество</th><th>Средняя цена</th></tr></thead><tbody><?php foreach ($demoReconciliation['positions'] as $position): ?><tr><td><?= h((string) ($position['symbol'] ?? '—')) ?></td><td><?= h((string) ($position['side'] ?? '—')) ?></td><td><?= h((string) ($position['quantity'] ?? '—')) ?></td><td><?= h((string) ($position['average_entry_price'] ?? '—')) ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
<?php endif; ?></section><?php endif; ?>

<?php if ($autotradingView === 'setup' && !$hasHedgePreview): ?><section class="panel"><h2>Публикация выбранной стратегии</h2>
<?php if (!$selectedStrategyPresent): ?><p class="empty">Сначала выберите тикер и стратегию в разделе «Базовые проверки», затем откройте «Автоторговля».</p><a href="basic-checks.php">Открыть базовые проверки</a>
<?php else: ?><h3>Выбрано в «Базовых проверках»</h3><div class="grid selected-summary">
<div class="stat"><span>Тикер</span><strong><?= h($symbol) ?></strong></div>
<div class="stat"><span>Условие входа</span><strong><?= h(entryDescription(['entry_config' => $entryConfig, 'entry_signal_mode' => $entrySignalMode, 'entry_direction_mode' => $entryDirectionMode])) ?></strong></div>
<div class="stat"><span>Условие выхода</span><strong><?= h(exitDescription($exitConfig)) ?></strong></div>
<div class="stat"><span>Период бэктеста</span><strong><?= h($startDate) ?> — <?= h($endDate) ?></strong></div>
</div>
<p class="note">Перед публикацией задайте ограничения. Для первой локальной paper версии нужны выключенный funding и режим без мартингейла. Выпущенный JSON хранится вне Git, рядом со SQLite состоянием.</p>
<?php if (!$paperStrategySupported): ?><p class="status warning"><?= $includeFunding ? 'В выбранном расчёте включён funding. ' : '' ?><?= $martingaleMode !== 'none' ? 'Выбран режим мартингейла. ' : '' ?><?= $category !== 'USDT-FUTURES' ? 'Выбран неподдерживаемый рынок. ' : '' ?>Вернитесь в «Базовые проверки», отключите неподдерживаемые параметры и откройте эту страницу снова.</p><?php endif; ?>
<form method="post"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="publish">
<?php foreach ($postHidden as $name => $value): ?><input type="hidden" name="<?= h($name) ?>" value="<?= h((string) $value) ?>"><?php endforeach; ?>
<div class="fields">
<label class="field">Максимальный размер ордера (USDT)<input name="max_order_notional_usdt" type="number" min="0.01" step="0.01" value="<?= h($balance) ?>" required></label>
<label class="field">Максимальная общая позиция (USDT)<input name="max_total_exposure_usdt" type="number" min="0.01" step="0.01" value="<?= h($balance) ?>" required></label>
<label class="field">Максимальный убыток за день (USDT)<input name="max_daily_loss_usdt" type="number" min="0.01" step="0.01" value="<?= h((string) max(0.01, (float) $balance * 0.1)) ?>" required></label>
<label class="field">Максимум открытых позиций<input name="max_open_positions" type="number" min="1" max="100" step="1" value="1" required></label>
</div><div class="actions"><button <?= !$isLocal || !$paperStrategySupported || ($status['service']['running'] ?? false) ? 'disabled' : '' ?>>Опубликовать эту выбранную стратегию</button></div>
</form><?php endif; ?></section><?php endif; ?>

<?php if ($autotradingView === 'runs'): ?><section class="panel"><h2>История запусков стратегий · <?= h(['paper' => 'Paper', 'demo' => 'Bitget Demo', 'real' => 'Bitget Real'][$accountMode] ?? 'Paper') ?></h2><p class="note">Запуски сохраняются для выбранного режима счёта. Остановка процесса не завершает сессию стратегии и не закрывает позиции.</p><?php if (($status['strategy_runs'] ?? []) === []): ?><p class="empty">Запусков пока нет.</p><?php else: ?><div class="table-wrap"><table><thead><tr><th>Стратегия</th><th>Началась · Киев</th><th>Закончилась · Киев</th><th>Причина завершения</th><th>Выделено</th><th>Максимум</th><th>Баланс при завершении</th><th>Состояние</th></tr></thead><tbody><?php foreach ($status['strategy_runs'] as $strategyRun): $reason = match ((string) ($strategyRun['stop_reason'] ?? '')) { 'manual' => 'Остановлена вручную кнопкой', 'loss_threshold' => 'Автоматически: достигнут порог снижения', 'initial_order_rejected' => 'Первая заявка отклонена; стратегия снята с портфеля', 'removed_from_portfolio' => 'Удалена из портфеля вручную', default => '—' }; ?><tr><td><?= h((string) ($strategyRun['strategy_name'] ?? $strategyRun['strategy_id'])) ?></td><td><?= h(timeMsLabel($strategyRun['started_at_ms'] ?? null)) ?></td><td><?= h(timeMsLabel($strategyRun['ended_at_ms'] ?? null)) ?></td><td><?= h($reason) ?></td><td><?= h((string) ($strategyRun['allocation_usdt'] ?? '—')) ?> USDT</td><td><?= h((string) ($strategyRun['peak_equity_usdt'] ?? '—')) ?> USDT</td><td><?= h((string) ($strategyRun['ending_equity_usdt'] ?? '—')) ?> USDT</td><td><?= ($strategyRun['status'] ?? '') === 'running' ? 'Работает' : (($strategyRun['status'] ?? '') === 'stopping' ? 'Закрывает позиции' : 'Завершена') ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></section><?php endif; ?>

<?php if ($accountMode === 'paper' && $autotradingView === 'deals'): ?><section class="panel"><h2>Последние сделки</h2><?php if (($status['recent_trades'] ?? []) === []): ?><p class="empty">Сделок пока нет.</p><?php else: ?><div class="table-wrap"><table><thead><tr><th>Стратегия</th><th>Тикер</th><th>Сторона</th><th>Количество</th><th>Вход · Киев</th><th>Выход · Киев</th><th>Результат · USDT</th></tr></thead><tbody><?php foreach (($status['recent_trades'] ?? []) as $trade): ?><tr><td><?= h($portfolioNameById[(string) ($trade['strategy_id'] ?? '')] ?? 'Старая стратегия') ?><?= ($trade['leg'] ?? 'single') !== 'single' ? ' · ' . h((string) $trade['leg']) : '' ?></td><td><?= h((string) $trade['symbol']) ?></td><td><?= h((string) $trade['side']) ?></td><td><?= h((string) $trade['quantity']) ?></td><td><?= h(timeMsLabel($trade['entry_ts'] ?? null)) ?></td><td><?= h(timeMsLabel($trade['exit_ts'] ?? null)) ?></td><td><?= h((string) $trade['pnl']) ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></section><?php endif; ?>

<?php if ($autotradingView === 'events'): ?><section class="panel"><h2>События и ошибки</h2><?php if (($status['recent_events'] ?? []) === []): ?><p class="empty">Событий пока нет.</p><?php else: ?><div class="table-wrap"><table><thead><tr><th>Время · Киев</th><th>Событие</th><th>Тикер</th><th>Причина и что проверить</th><th>Технические данные</th></tr></thead><tbody><?php foreach ($status['recent_events'] as $event): $eventPayload = is_array($event['payload'] ?? null) ? $event['payload'] : []; ?><tr><td><?= h(timeMsLabel($event['timestamp_ms'] ?? null)) ?><?php if ((int) ($eventPayload['repeat_count'] ?? 1) > 1): ?><br><small>Повторилось <?= h((string) $eventPayload['repeat_count']) ?> раз · с <?= h(timeMsLabel($eventPayload['first_seen_ms'] ?? null)) ?></small><?php endif; ?></td><td><?= h(autotradingEventTitle($event)) ?></td><td><?= h((string) ($event['symbol'] ?? '—')) ?></td><td><?= h(autotradingEventExplanation($event)) ?></td><td><details><summary>Подробности</summary><pre><?= h(json_encode($eventPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '') ?></pre></details></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></section><?php endif; ?>
</main>
<?php if (in_array($autotradingView, ['state', 'events'], true) && !empty($status['service']['running'])): ?><script>setTimeout(()=>location.reload(),60000);</script><?php endif; ?>
<?php if (in_array($accountMode, ['paper', 'demo', 'real'], true) && $isLocal): ?>
<script>
(()=>{
const panel=document.querySelector('#autotrading-history');
if(!panel)return;
const statusNode=document.querySelector('#history-status'),summaryNode=document.querySelector('#history-summary'),chart=document.querySelector('#history-chart'),tableHost=document.querySelector('#history-trades'),comparisonNote=document.querySelector('#history-comparison-note'),chartModeHost=document.querySelector('#history-chart-modes'),chartModeNote=document.querySelector('#history-chart-mode-note'),chartNote=document.querySelector('#history-chart-note'),chartLegend=document.querySelector('#history-chart-legend'),chartModeButtons=[...document.querySelectorAll('[data-chart-mode]')],ns='http://www.w3.org/2000/svg';
const mode=panel.dataset.mode,symbol=panel.dataset.symbol,strategyId=panel.dataset.strategyId,startDate=panel.dataset.startDate,endDate=panel.dataset.endDate,hedgeSymbols=JSON.parse(panel.dataset.hedgeSymbols||'[]');
let chartView='ticker';chartModeHost.hidden=hedgeSymbols.length!==2;chartModeNote.textContent='';
const openPositions=JSON.parse(document.querySelector('#history-open-positions')?.textContent||'[]').filter(position=>!strategyId||position.strategy_id===strategyId);
let chartOpenTradeOffset=0,chartOpenPositionKeys=[],chartSymbol=symbol,chartHoverRequest=0,activeShowTradeOverlayForRow=()=>{};
const chartHistoryBySymbol=new Map(),chartHistoryPendingBySymbol=new Map();
const openPositionKey=position=>[position.strategy_id,position.symbol,position.leg,position.entry_timestamp_ms].join('|');
const money=value=>Number(value||0).toLocaleString('ru-RU',{minimumFractionDigits:2,maximumFractionDigits:4});
const dateTime=value=>Number(value)>0?new Date(Number(value)).toLocaleString('ru-RU',{timeZone:'Europe/Kyiv',day:'2-digit',month:'2-digit',year:'2-digit',hour:'2-digit',minute:'2-digit',second:'2-digit'}):'—';
const sum=trades=>trades.reduce((total,trade)=>total+Number(trade.pnl||0),0);
function svg(tag,attributes={},parent=chart){const node=document.createElementNS(ns,tag);for(const[name,value]of Object.entries(attributes))node.setAttribute(name,String(value));parent.append(node);return node}
function metric(label,value){const box=document.createElement('div');box.className='stat';const caption=document.createElement('span');caption.textContent=label;const result=document.createElement('strong');result.textContent=value;box.append(caption,result);summaryNode.append(box)}
function cell(row,value){const node=document.createElement('td');node.textContent=String(value??'—');row.append(node)}
function tradeText(trade){return trade?`${dateTime(trade.entry_ts)} · ${trade.entry_price} → ${dateTime(trade.exit_ts)} · ${trade.exit_price}`:'—'}
function renderSymbolChart(data,selectedSymbol){chartSymbol=selectedSymbol;chartView='ticker';chartLegend.replaceChildren();chartLegend.hidden=true;chartModeNote.textContent='';chartModeButtons.forEach(button=>button.setAttribute('aria-pressed',String(button.dataset.chartMode==='ticker')));const actual=data.actual?.trades||[],paper=data.paper?.trades||[],quoteSource=(data.actual?.quotes||[]).length?data.actual:data.paper;renderChart(quoteSource?.quotes||[],mode==='paper'?[]:actual,mode==='paper'?actual:paper);if((quoteSource?.quotes||[]).length)statusNode.textContent=`${selectedSymbol} · ${startDate} — ${endDate} · ${(quoteSource.quotes||[]).length} точек графика${mode==='paper'?' · котировки Paper':''}`}
function renderComparisonChart(){chartLegend.replaceChildren();chartLegend.hidden=false;const colors=['#86a8ff','#f0bd65'],series=hedgeSymbols.map((ticker,index)=>{const data=chartHistoryBySymbol.get(ticker),source=(data?.actual?.quotes||[]).length?data.actual:data?.paper,quotes=(source?.quotes||[]).map(item=>({t:Number(item.ts),p:Number(item.last)})).filter(point=>Number.isFinite(point.t)&&Number.isFinite(point.p)&&point.p>0);if(!quotes.length)return null;const base=quotes[0].p;return{ticker,index,color:colors[index],label:`${index===0?'Лонг':'Шорт'} ${ticker}`,points:quotes.map(point=>({t:point.t,v:index===0?100*point.p/base:100*(2-point.p/base)}))}}).filter(Boolean);chart.replaceChildren();activeShowTradeOverlayForRow=()=>{};activeClearTradeOverlayForRow=()=>{};if(series.length!==2){statusNode.textContent='Для сравнения нужны котировки обоих тикеров за выбранный период.';return}const W=1100,H=360,p={l:76,r:18,t:18,b:44},all=series.flatMap(item=>item.points),xmin=Math.min(...all.map(point=>point.t)),xmax=Math.max(...all.map(point=>point.t)),values=all.map(point=>point.v);let ymin=Math.min(...values),ymax=Math.max(...values);if(ymin===ymax){ymin-=1;ymax+=1}const pad=(ymax-ymin)*.08||1;ymin-=pad;ymax+=pad;const x=time=>p.l+(time-xmin)/Math.max(1,xmax-xmin)*(W-p.l-p.r),y=value=>p.t+(ymax-value)/(ymax-ymin)*(H-p.t-p.b);chart.setAttribute('aria-label',`Сравнение доходности лонга ${hedgeSymbols[0]} и шорта ${hedgeSymbols[1]}`);for(let i=0;i<5;i++){const value=ymin+(ymax-ymin)*i/4,py=y(value);svg('line',{x1:p.l,x2:W-p.r,y1:py,y2:py,class:'grid-line'});const label=svg('text',{x:p.l-8,y:py+4,'text-anchor':'end'});label.textContent=money(value)}for(let i=0;i<5;i++){const time=xmin+(xmax-xmin)*i/4,px=x(time);svg('line',{x1:px,x2:px,y1:p.t,y2:H-p.b,class:'grid-line'});const label=svg('text',{x:px,y:H-13,'text-anchor':'middle'});label.textContent=new Date(time).toLocaleString('ru-RU',{timeZone:'Europe/Kyiv',day:'2-digit',month:'2-digit',hour:'2-digit',minute:'2-digit'})}for(const item of series){svg('path',{d:item.points.map((point,index)=>`${index?'L':'M'}${x(point.t).toFixed(2)},${y(point.v).toFixed(2)}`).join(' '),class:'comparison-line','stroke':item.color});const legend=document.createElement('span'),dot=document.createElement('i');dot.style.background=item.color;legend.append(dot,document.createTextNode(item.label));chartLegend.append(legend)}const crosshair=svg('g',{'aria-hidden':'true',style:'display:none'}),vertical=svg('line',{class:'trade-crosshair-line'},crosshair),horizontal=svg('line',{class:'trade-crosshair-line'},crosshair),timeLabel=svg('text',{class:'trade-crosshair-label','text-anchor':'middle'},crosshair),valueLabel=svg('text',{class:'trade-crosshair-label','text-anchor':'end'},crosshair);chart.onpointermove=event=>{const matrix=chart.getScreenCTM();if(!matrix)return;const pointer=chart.createSVGPoint();pointer.x=event.clientX;pointer.y=event.clientY;const local=pointer.matrixTransform(matrix.inverse());if(local.x<p.l||local.x>W-p.r||local.y<p.t||local.y>H-p.b){crosshair.style.display='none';return}const time=xmin+(local.x-p.l)/(W-p.l-p.r)*(xmax-xmin),value=ymax-(local.y-p.t)/(H-p.t-p.b)*(ymax-ymin);crosshair.style.display='';vertical.setAttribute('x1',local.x);vertical.setAttribute('x2',local.x);vertical.setAttribute('y1',p.t);vertical.setAttribute('y2',H-p.b);horizontal.setAttribute('x1',p.l);horizontal.setAttribute('x2',W-p.r);horizontal.setAttribute('y1',local.y);horizontal.setAttribute('y2',local.y);timeLabel.setAttribute('x',Math.max(p.l+48,Math.min(W-p.r-48,local.x)));timeLabel.setAttribute('y',H-3);timeLabel.textContent=dateTime(time);valueLabel.setAttribute('x',p.l-8);valueLabel.setAttribute('y',local.y-5<p.t+11?local.y+13:local.y-5);valueLabel.textContent=money(value)};chart.onpointerleave=()=>{crosshair.style.display='none'};chartView='comparison';updateChartModeButtons();chartModeNote.textContent='Индекс начинается с 100 на первом снимке периода: лонг растёт при росте цены, шорт — при её снижении.';chartNote.textContent='Синяя линия — индекс лонга, золотая — индекс шорта. Индекс 100 соответствует цене на первом снимке периода; комиссии не включены.';statusNode.textContent=`Сравнение лонга ${hedgeSymbols[0]} и шорта ${hedgeSymbols[1]} · ${startDate} — ${endDate}`}
async function selectChartView(nextView){if(nextView==='ticker'){const data=chartHistoryBySymbol.get(chartSymbol)||await loadSymbolHistory(chartSymbol);renderSymbolChart(data,chartSymbol);return}if(hedgeSymbols.length!==2)return;chartView='comparison';updateChartModeButtons();statusNode.textContent='Загружаю котировки лонга и шорта…';try{await Promise.all(hedgeSymbols.map(loadSymbolHistory));renderComparisonChart()}catch(error){chartView='ticker';updateChartModeButtons();statusNode.textContent=error.message||'Не удалось загрузить котировки пары'}}
chartModeButtons.forEach(button=>button.addEventListener('click',()=>selectChartView(button.dataset.chartMode)));
function loadSymbolHistory(selectedSymbol){if(chartHistoryBySymbol.has(selectedSymbol))return Promise.resolve(chartHistoryBySymbol.get(selectedSymbol));if(chartHistoryPendingBySymbol.has(selectedSymbol))return chartHistoryPendingBySymbol.get(selectedSymbol);const params=new URLSearchParams({mode,symbol:selectedSymbol,strategy_id:strategyId,start_date:startDate,end_date:endDate}),pending=fetch(`autotrading-history.php?${params}`,{headers:{Accept:'application/json'},cache:'no-store'}).then(async response=>{const data=await response.json();if(!response.ok||data.status!=='ready')throw new Error(data.message||`Не удалось загрузить график ${selectedSymbol}`);chartHistoryBySymbol.set(selectedSymbol,data);return data}).finally(()=>chartHistoryPendingBySymbol.delete(selectedSymbol));chartHistoryPendingBySymbol.set(selectedSymbol,pending);return pending}
let activeClearTradeOverlayForRow=()=>{};
function restoreChartAfterCancelledHover(request){if(request!==chartHoverRequest||chart.childElementCount)return;if(chartView==='comparison'&&hedgeSymbols.length===2&&hedgeSymbols.every(ticker=>chartHistoryBySymbol.has(ticker))){renderComparisonChart();return}const previous=chartHistoryBySymbol.get(chartSymbol);if(previous)renderSymbolChart(previous,chartSymbol)}
function cancelHistoryRowHover(row){if(row.matches(':hover')||document.activeElement===row)return;const request=++chartHoverRequest;activeClearTradeOverlayForRow();window.setTimeout(()=>restoreChartAfterCancelledHover(request),0)}
function bindHistoryRowHover(){document.querySelectorAll('.history-trade-row').forEach(row=>{const activate=async()=>{const request=++chartHoverRequest,targetSymbol=(row.dataset.symbol||symbol).toUpperCase(),needsChart=targetSymbol!==chartSymbol||chartView!=='ticker';if(needsChart){activeClearTradeOverlayForRow();chart.replaceChildren();chartLegend.replaceChildren();chartLegend.hidden=true;statusNode.textContent=`Загружаю график ${targetSymbol}…`}try{let data=chartHistoryBySymbol.get(targetSymbol);if(!data)data=await loadSymbolHistory(targetSymbol);if(request!==chartHoverRequest)return;if(needsChart)renderSymbolChart(data,targetSymbol);if(request===chartHoverRequest)activeShowTradeOverlayForRow(row)}catch(error){if(request===chartHoverRequest)statusNode.textContent=error.message||'Не удалось загрузить график тикера'}};row.onpointerenter=activate;row.onfocus=activate;row.onpointerleave=()=>cancelHistoryRowHover(row);row.onblur=()=>cancelHistoryRowHover(row)})}
function renderChart(quotes,actualTrades,paperTrades){
activeShowTradeOverlayForRow=()=>{};activeClearTradeOverlayForRow=()=>{};chart.replaceChildren();
const points=quotes.map(item=>({t:Number(item.ts),p:Number(item.last)})).filter(item=>Number.isFinite(item.t)&&Number.isFinite(item.p));
const allTrades=[...actualTrades.map(trade=>({...trade,source:'actual'})),...paperTrades.map(trade=>({...trade,source:'paper'}))];
const openTrades=openPositions.filter(position=>position.symbol===chartSymbol).map(position=>({source:mode==='paper'?'paper':(mode==='demo'?'actual':'real'),side:position.side,entry_ts:Number(position.entry_timestamp_ms),entry_timestamp_ms:Number(position.entry_timestamp_ms),entry_price:Number(position.entry_price),exit_ts:null,exit_price:null,pnl:Number(position.unrealized_pnl_usdt||0),symbol:position.symbol,strategy_id:position.strategy_id,leg:position.leg,position_key:openPositionKey(position),open:true}));
const chartTrades=[...allTrades,...openTrades];
if(!points.length){statusNode.textContent='За выбранный период нет сохранённых котировок для графика.';return}
chartOpenTradeOffset=allTrades.length;
chartOpenPositionKeys=openTrades.map(position=>position.position_key);
const W=1100,H=360,p={l:76,r:18,t:18,b:44},times=points.map(item=>item.t),chartStartPoint=points[0],chartEndPoint=points[points.length-1],prices=[...points.map(item=>item.p),...chartTrades.flatMap(item=>[item.entry_price,item.exit_price].filter(value=>value!==null&&value!==undefined&&value!=='').map(Number)).filter(Number.isFinite)];
let xmin=Math.min(...times),xmax=Math.max(...times),ymin=Math.min(...prices),ymax=Math.max(...prices);if(xmin===xmax){xmin-=60000;xmax+=60000}if(ymin===ymax){ymin*=.999;ymax*=1.001}const pad=(ymax-ymin)*.08;ymin-=pad;ymax+=pad;
const x=t=>p.l+(t-xmin)/(xmax-xmin)*(W-p.l-p.r),y=v=>p.t+(ymax-v)/(ymax-ymin)*(H-p.t-p.b);
for(let i=0;i<5;i++){const value=ymin+(ymax-ymin)*i/4,py=y(value);svg('line',{x1:p.l,x2:W-p.r,y1:py,y2:py,class:'grid-line'});const label=svg('text',{x:p.l-8,y:py+4,'text-anchor':'end'});label.textContent=money(value)}
for(let i=0;i<5;i++){const time=xmin+(xmax-xmin)*i/4,px=x(time);svg('line',{x1:px,x2:px,y1:p.t,y2:H-p.b,class:'grid-line'});const label=svg('text',{x:px,y:H-13,'text-anchor':'middle'});label.textContent=new Date(time).toLocaleString('ru-RU',{timeZone:'Europe/Kyiv',day:'2-digit',month:'2-digit',hour:'2-digit',minute:'2-digit'})}
svg('path',{d:points.map((item,index)=>`${index?'L':'M'}${x(item.t).toFixed(2)},${y(item.p).toFixed(2)}`).join(' '),class:'price-line'});
const overlay=svg('g',{'aria-hidden':'true'}), overlays=[];
for(const[index,trade]of chartTrades.entries()){const entry=Number(trade.entry_ts),exit=Number(trade.exit_ts),entryPrice=Number(trade.entry_price),exitPrice=Number(trade.exit_price);if(!Number.isFinite(entry)||!Number.isFinite(entryPrice))continue;const klass=trade.source==='paper'?'paper-trade':'actual-trade',entryVisible=entry>=xmin&&entry<=xmax,exitVisible=Number.isFinite(exit)&&exit>=xmin&&exit<=xmax;if(entryVisible){const dot=svg('circle',{cx:x(entry),cy:y(entryPrice),r:5,class:`${klass} trade-entry`});const title=document.createElementNS(ns,'title');title.textContent=`${trade.source==='paper'?'Paper':'Bitget'} · вход ${trade.side} · ${dateTime(entry)} · ${entryPrice}`;dot.append(title)}if(exitVisible&&Number.isFinite(exitPrice)){const dot=svg('circle',{cx:x(exit),cy:y(exitPrice),r:4,class:klass});const title=document.createElementNS(ns,'title');title.textContent=`Выход · ${dateTime(exit)} · ${exitPrice}`;dot.append(title)}overlays[index]={trade,entry,exit,entryPrice,exitPrice,klass,entryVisible,exitVisible}}
const crosshair=svg('g',{'aria-hidden':'true',style:'display:none'});
const crosshairVertical=svg('line',{class:'trade-crosshair-line'},crosshair),crosshairHorizontal=svg('line',{class:'trade-crosshair-line'},crosshair);
const crosshairTime=svg('text',{class:'trade-crosshair-label','text-anchor':'middle'},crosshair),crosshairPrice=svg('text',{class:'trade-crosshair-label','text-anchor':'end'},crosshair);
chart.onpointermove=event=>{const matrix=chart.getScreenCTM();if(!matrix)return;const pointer=chart.createSVGPoint();pointer.x=event.clientX;pointer.y=event.clientY;const local=pointer.matrixTransform(matrix.inverse());if(local.x<p.l||local.x>W-p.r||local.y<p.t||local.y>H-p.b){crosshair.style.display='none';return}const time=xmin+(local.x-p.l)/(W-p.l-p.r)*(xmax-xmin),price=ymax-(local.y-p.t)/(H-p.t-p.b)*(ymax-ymin);crosshair.style.display='';crosshairVertical.setAttribute('x1',local.x);crosshairVertical.setAttribute('x2',local.x);crosshairVertical.setAttribute('y1',p.t);crosshairVertical.setAttribute('y2',H-p.b);crosshairHorizontal.setAttribute('x1',p.l);crosshairHorizontal.setAttribute('x2',W-p.r);crosshairHorizontal.setAttribute('y1',local.y);crosshairHorizontal.setAttribute('y2',local.y);crosshairTime.setAttribute('x',Math.max(p.l+48,Math.min(W-p.r-48,local.x)));crosshairTime.setAttribute('y',H-3);crosshairTime.textContent=dateTime(time);crosshairPrice.setAttribute('x',p.l-8);crosshairPrice.setAttribute('y',local.y-5<p.t+11?local.y+13:local.y-5);crosshairPrice.textContent=money(price)};
chart.onpointerleave=()=>{crosshair.style.display='none'};
function showTradeOverlay(index){overlay.replaceChildren();const item=overlays[index];if(!item)return;const{trade,entry,exit,entryPrice,exitPrice,klass,entryVisible,exitVisible}=item,color=trade.source==='paper'?'#f0bd65':(Number(trade.pnl)>=0?'#42d6a4':'#ff7188');if(entryVisible&&exitVisible){svg('line',{x1:x(entry),y1:y(entryPrice),x2:x(exit),y2:y(exitPrice),class:`trade-link ${klass}`},overlay)}const highlightStartTime=trade.open&&entry<xmin?chartStartPoint.t:entry,highlightStartPrice=trade.open&&entry<xmin?chartStartPoint.p:entryPrice;if(trade.open&&highlightStartTime<=chartEndPoint.t){svg('line',{x1:x(highlightStartTime),y1:y(highlightStartPrice),x2:x(chartEndPoint.t),y2:y(chartEndPoint.p),class:`trade-link ${klass}`},overlay)}for(const[time,price]of [...(entryVisible?[[entry,entryPrice]]:[]),...(exitVisible&&Number.isFinite(exitPrice)?[[exit,exitPrice]]:[])]){svg('line',{x1:x(time),x2:x(time),y1:p.t,y2:H-p.b,class:'trade-hover-line'},overlay);const label=svg('text',{x:x(time),y:Math.max(p.t+14,y(price)-27),'text-anchor':'middle',class:'trade-hover-arrow',fill:color},overlay);label.textContent=time===entry?(trade.side==='short'?'↓':'↑'):'↕';svg('text',{x:x(time),y:Math.min(H-p.b-7,y(price)+27),'text-anchor':'middle',class:'trade-hover-price'},overlay).textContent=String(price)}if((exitVisible&&Number.isFinite(exitPrice))||(trade.open&&entryVisible&&chartEndPoint.t>=entry)){const faceTime=trade.open?chartEndPoint.t:exit,facePrice=trade.open?chartEndPoint.p:exitPrice,face=svg('g',{transform:`translate(${x(faceTime)} ${Math.max(p.t+12,y(facePrice)-27)})`},overlay);svg('circle',{r:9,class:'trade-face',stroke:color},face);svg('circle',{cx:-3,cy:-2,r:1.1,class:'trade-face-part',stroke:color},face);svg('circle',{cx:3,cy:-2,r:1.1,class:'trade-face-part',stroke:color},face);svg('path',{d:Number(trade.pnl)>=0?'M -4 2 Q 0 6 4 2':'M -4 5 Q 0 1 4 5',class:'trade-face-part',stroke:color},face);const title=document.createElementNS(ns,'title');title.textContent=`${trade.open?'Плавающий результат':(Number(trade.pnl)>=0?'Прибыль':'Убыток')}: ${money(trade.pnl)} USDT`;face.append(title)}}
activeShowTradeOverlayForRow=row=>{const positionIndex=row.dataset.openPositionKey?chartOpenPositionKeys.indexOf(row.dataset.openPositionKey):-1;showTradeOverlay(positionIndex>=0?chartOpenTradeOffset+positionIndex:Number(row.dataset.chartIndex))};activeClearTradeOverlayForRow=()=>overlay.replaceChildren();
}
function renderTrades(data){
const actual=data.actual.trades||[],paper=data.paper.trades||[];summaryNode.replaceChildren();
metric(mode==='paper'?'Закрытые сделки Paper':(mode==='demo'?'Закрытые сделки Bitget Demo':'Закрытые сделки Bitget Real'),String(actual.length));metric(mode==='paper'?'Результат Paper':(mode==='demo'?'Результат Bitget Demo':'Результат Bitget Real'),`${money(sum(actual))} USDT`);
if(mode==='demo'){
metric('Сделки Paper',data.paper.available?String(paper.length):'нет данных');metric('Результат Paper',data.paper.available?`${money(sum(paper))} USDT`:'—');
const paired=Math.min(actual.length,paper.length),sideMatches=Array.from({length:paired},(_,index)=>actual[index].side===paper[index].side).filter(Boolean).length,pnlMatches=Array.from({length:paired},(_,index)=>Math.sign(Number(actual[index].pnl))===Math.sign(Number(paper[index].pnl))).filter(Boolean).length;
metric('Сторона совпала',`${sideMatches} из ${paired}`);metric('Знак результата совпал',`${pnlMatches} из ${paired}`);
comparisonNote.textContent=strategyId?`Сделки сопоставлены по порядку открытия выбранной стратегии и пары. Открытые позиции показаны отдельно независимо от периода; их плавающий результат ещё не реализован. Разница закрытых сделок: Bitget Demo минус Paper.`:'Выбраны все стратегии. Для корректного сопоставления выберите одну стратегию: Paper и Demo могут иметь разный состав портфеля. Открытые позиции показаны отдельно независимо от периода.';
const table=document.createElement('table');table.className='history-table';const head=document.createElement('thead'),headRow=document.createElement('tr');for(const label of ['№','Вход / выход Paper','Результат Paper','Вход / выход Demo','Результат Demo','Разница','Сопоставление']){const th=document.createElement('th');th.textContent=label;headRow.append(th)}head.append(headRow);table.append(head);const body=document.createElement('tbody');
for(let index=0;index<Math.max(actual.length,paper.length);index++){const a=actual[index],p=paper[index],row=document.createElement('tr');row.className='history-trade-row';row.tabIndex=0;row.dataset.chartIndex=String(index);row.dataset.symbol=String(a?.symbol??p?.symbol??symbol);cell(row,index+1);cell(row,tradeText(p));cell(row,p?`${money(p.pnl)} USDT`:'—');cell(row,tradeText(a));cell(row,a?`${money(a.pnl)} USDT`:'—');cell(row,a&&p?`${money(Number(a.pnl)-Number(p.pnl))} USDT`:'—');cell(row,a&&p?(a.side===p.side?(Math.sign(Number(a.pnl))===Math.sign(Number(p.pnl))?'Сторона и результат совпали':'Сторона совпала, результат отличается'):'Сторона отличается'):'Нет пары');const pnl=Number(a?.pnl??p?.pnl??0);row.classList.add((a?.open?'history-trade-open-':'history-trade-')+(pnl>0?'positive':(pnl<0?'negative':'flat')));body.append(row)}
for(const position of openPositions){const row=document.createElement('tr');row.className='history-trade-row history-trade-open-'+(Number(position.unrealized_pnl_usdt)>0?'positive':(Number(position.unrealized_pnl_usdt)<0?'negative':'flat'));row.tabIndex=0;row.dataset.symbol=position.symbol;row.dataset.openPositionKey=openPositionKey(position);const chartPositionIndex=chartOpenPositionKeys.indexOf(row.dataset.openPositionKey);row.dataset.chartIndex=String(chartPositionIndex<0?Number.NaN:chartOpenTradeOffset+chartPositionIndex);cell(row,`Открыта · ${position.symbol}`);cell(row,'—');cell(row,'—');cell(row,`${position.side} · ${position.quantity} · ${dateTime(position.entry_timestamp_ms)} · ${position.entry_price} → открыта`);cell(row,position.unrealized_pnl_usdt==null?'Нет свежей котировки':`Плавающий результат ${money(position.unrealized_pnl_usdt)} USDT`);cell(row,'—');cell(row,'Позиция открыта');body.append(row)}
table.append(body);tableHost.replaceChildren(table);
}else{
comparisonNote.textContent=mode==='paper'?'Показаны виртуальные сделки Paper и текущие открытые позиции. Плавающий результат позиции ещё не является реализованной прибылью.':'Показаны закрытые реальные сделки Bitget и текущие открытые позиции. Плавающий результат позиции не является реализованной прибылью.';
const table=document.createElement('table');table.className='history-table';const head=document.createElement('thead'),headRow=document.createElement('tr');for(const label of ['№','Состояние','Сторона','Количество','Вход · время','Вход · цена','Выход · время','Выход · цена','Результат · USDT']){const th=document.createElement('th');th.textContent=label;headRow.append(th)}head.append(headRow);table.append(head);const body=document.createElement('tbody');for(const[index,trade]of actual.entries()){const row=document.createElement('tr');row.className='history-trade-row';row.tabIndex=0;row.dataset.chartIndex=String(index);row.dataset.symbol=String(trade.symbol??symbol);for(const value of [index+1,'Закрыта',trade.side,trade.quantity,dateTime(trade.entry_ts),trade.entry_price,dateTime(trade.exit_ts),trade.exit_price,`${money(trade.pnl)} USDT`])cell(row,value);row.classList.add(Number(trade.pnl)>0?'history-trade-positive':(Number(trade.pnl)<0?'history-trade-negative':'history-trade-flat'));body.append(row)}for(const position of openPositions){const row=document.createElement('tr');row.className='history-trade-row history-trade-open-'+(Number(position.unrealized_pnl_usdt)>0?'positive':(Number(position.unrealized_pnl_usdt)<0?'negative':'flat'));row.tabIndex=0;row.dataset.symbol=position.symbol;row.dataset.openPositionKey=openPositionKey(position);const chartPositionIndex=chartOpenPositionKeys.indexOf(row.dataset.openPositionKey);row.dataset.chartIndex=String(chartPositionIndex<0?Number.NaN:chartOpenTradeOffset+chartPositionIndex);for(const value of [`Открыта · ${position.symbol}`,'Открыта',position.side,position.quantity,dateTime(position.entry_timestamp_ms),position.entry_price,'—','—',position.unrealized_pnl_usdt==null?'Нет свежей котировки':`Плавающий ${money(position.unrealized_pnl_usdt)} USDT`])cell(row,value);body.append(row)}table.append(body);tableHost.replaceChildren(table)
}
bindHistoryRowHover();chartHistoryBySymbol.set(symbol,data);renderSymbolChart(data,symbol);const otherTickers=[...new Set([...hedgeSymbols,...openPositions.map(position=>position.symbol)])].filter(ticker=>ticker!==symbol);if(otherTickers.length<=2)for(const ticker of otherTickers)loadSymbolHistory(ticker).catch(()=>{});
}
const query=new URLSearchParams({mode,symbol,strategy_id:strategyId,start_date:startDate,end_date:endDate});
fetch(`autotrading-history.php?${query}`,{headers:{Accept:'application/json'},cache:'no-store'}).then(async response=>{const data=await response.json();if(!response.ok||data.status!=='ready')throw new Error(data.message||'Не удалось загрузить сделки.');renderTrades(data)}).catch(error=>{statusNode.textContent=error.message||'Не удалось загрузить историю сделок.';tableHost.replaceChildren()}).finally(()=>{if(!document.hidden&&<?= !empty($status['service']['running']) ? 'true' : 'false' ?>)setTimeout(()=>location.reload(),60000)});
})();
</script>
<?php endif; ?>
</body></html>
