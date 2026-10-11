<?php
declare(strict_types=1);

session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict', 'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off']);
session_start();
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
function timeMsLabel(mixed $value): string {
    if (!is_numeric($value) || (int) $value <= 0) return '—';
    return (new DateTimeImmutable('@' . (int) floor((int) $value / 1000)))->setTimezone(new DateTimeZone('Europe/Kyiv'))->format('d.m.Y H:i:s');
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
    foreach (['BITGET_DEMO_ENV_FILE', 'BITGET_DEMO_API_KEY', 'BITGET_DEMO_API_SECRET', 'BITGET_DEMO_API_PASSPHRASE'] as $name) {
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
$demoEnvFile = getenv('BITGET_DEMO_ENV_FILE') ?: '';
if ($demoEnvFile === '' && ($userHome = getenv('HOME')) !== false) {
    $localDemoEnv = rtrim($userHome, '/') . '/.ai/home/.local/bitget-autotrading-demo.env';
    if (is_file($localDemoEnv)) $demoEnvFile = $localDemoEnv;
}
$demoEnvironment = [];
if ($demoEnvFile !== '') $demoEnvironment['BITGET_DEMO_ENV_FILE'] = $demoEnvFile;
$GLOBALS['autotrading_demo_env'] = $demoEnvironment;
$uid = function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();
$runtimeRoot = getenv('BITGET_AUTOTRADING_DIR') ?: '/var/tmp';
$runtime = rtrim($runtimeRoot, '/') . '/bitget-autotrading-' . $uid;
if (!is_dir($runtime) && !mkdir($runtime, 0700, true) && !is_dir($runtime)) throw new RuntimeException('Не удалось создать каталог локального состояния.');
if (is_link($runtime) || fileowner($runtime) !== $uid) throw new RuntimeException('Каталог локального состояния имеет неподходящего владельца.');
chmod($runtime, 0700);
$statePath = $runtime . '/state.sqlite';
$controlPath = $runtime . '/controls.json';
$pidPath = $runtime . '/paper.pid.json';
$logPath = $runtime . '/paper.log';
$activeReleasePath = $runtime . '/active-release.json';
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
$balance = $params('balance', '10');
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
        if ($action === 'publish') {
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
            $healthArgs = array_merge(['status'], $argsBase);
            [$statusCode, $statusOut] = runPython($healthArgs, $root, $python);
            $health = $statusCode === 0 ? json_decode($statusOut, true) : null;
            if (is_array($health) && ($health['service']['running'] ?? false)) throw new RuntimeException('Сначала остановите paper процесс, затем публикуйте новую версию стратегии.');
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
            $activeTemporary = $runtime . '/.active-release.' . bin2hex(random_bytes(4)) . '.tmp';
            if (!copy($releasePath, $activeTemporary) || !rename($activeTemporary, $activeReleasePath)) {
                @unlink($activeTemporary);
                throw new RuntimeException('Не удалось выбрать опубликованную версию.');
            }
            chmod($activeReleasePath, 0600);
            $message = 'Стратегия опубликована: ' . $releaseId . '. Её можно запускать в paper режиме.';
            $messageType = 'success';
        } elseif ($action === 'demo-reconcile') {
            $demoSymbol = strtoupper((string) ($_POST['symbol'] ?? $symbol));
            if (!preg_match('/^[A-Z0-9]{2,40}$/', $demoSymbol)) throw new RuntimeException('Выбран некорректный тикер для проверки Demo.');
            unset($_SESSION['autotrading_demo_status']);
            [$code, $out, $err] = runPython(['demo-reconcile', '--symbols', $demoSymbol], $root, $python);
            $report = json_decode($out, true);
            if (!is_array($report) || ($report['mode'] ?? null) !== 'demo') throw new RuntimeException('Не удалось получить результат проверки Demo API: ' . ($err ?: $out ?: 'пустой ответ исполнителя.'));
            $_SESSION['autotrading_demo_status'] = $report;
            $demoReconciliation = $report;
            $message = ($report['matched'] ?? false) ? 'Demo сверка завершена: расхождений нет.' : 'Demo сверка завершена: обнаружено расхождение или ошибка подключения.';
            $messageType = ($report['matched'] ?? false) ? 'success' : 'warning';
        } elseif ($action === 'start') {
            if (!is_file($activeReleasePath)) throw new RuntimeException('Сначала опубликуйте выбранную стратегию.');
            [$code, $out, $err] = runPython(['service-start', '--release', $activeReleasePath, '--state', $statePath, '--control', $controlPath, '--pid-file', $pidPath, '--log', $logPath, '--interval', '5'], $root, $python);
            if ($code !== 0) throw new RuntimeException($err ?: $out ?: 'Служба не запустилась.');
            $message = 'Paper исполнитель запущен. Он использует только публичные котировки.'; $messageType = 'success';
        } elseif ($action === 'stop') {
            [$code, $out, $err] = runPython(['service-stop', '--state', $statePath, '--pid-file', $pidPath], $root, $python);
            if ($code !== 0) throw new RuntimeException($err ?: $out ?: 'Не удалось остановить службу.');
            $message = 'Paper процесс остановлен. Сохранённые виртуальные позиции останутся в состоянии и восстановятся при следующем запуске.'; $messageType = 'success';
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
$published = is_file($activeReleasePath) ? json_decode((string) file_get_contents($activeReleasePath), true) : null;
$selectedStrategyPresent = isset($_GET['symbol']);
$paperStrategySupported = $category === 'USDT-FUTURES' && !$includeFunding && $martingaleMode === 'none';
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
 .published-summary{margin:16px 0;padding:14px;border:1px solid #3c527e;border-radius:12px;background:#101a30}.published-summary .stat strong,.selected-summary .stat strong{display:block;font-size:14px;line-height:1.45;overflow-wrap:anywhere}.how-to ol{margin:8px 0 12px;padding-left:23px}.how-to li{margin:5px 0}
</style></head><body><main class="wrap">
<div class="muted">Bitget · локальный исполнитель</div><h1>Автоторговля</h1>
<nav class="nav" aria-label="Разделы"><a href="./">Базовые проверки</a><a href="hedging.php">Хеджирование</a><a href="favorites.php">Избранное</a><a href="settings.php">Настройки</a><a href="autotrading.php" aria-current="page">Автоторговля</a><a href="autotrading-pi.php">Raspberry Pi</a></nav>
<section class="panel how-to"><h2>Как попробовать выбранную стратегию</h2><ol><li>В «Базовых проверках» выберите тикер, порог входа и выход. Затем нажмите «Автоторговля».</li><li>Ниже сверьте выбранные параметры и нажмите «Опубликовать эту выбранную стратегию».</li><li>В верхнем блоке «Состояние исполнителя» нажмите «Запустить опубликованную стратегию».</li></ol><p class="note">Это paper-режим: исполнитель читает публичные котировки и создаёт только виртуальные сделки, ордера на Bitget не отправляются. После запуска он может ждать сигнала; статус «Работает» при нуле открытых позиций означает, что условие входа ещё не сработало.</p></section>
<?php if ($message !== ''): ?><p class="status <?= h($messageType) ?>" role="status"><?= h($message) ?></p><?php endif; ?>
<?php if (!$isLocal): ?><p class="status warning">Просмотр доступен, но управление работает только при открытии страницы с этого компьютера.</p><?php endif; ?>
<section class="panel"><h2>Состояние исполнителя</h2><div class="grid">
<div class="stat"><span>Процесс</span><strong><?= ($status['service']['running'] ?? false) ? 'Работает' : 'Остановлен' ?></strong></div>
<div class="stat"><span>Режим</span><strong>Paper</strong></div><div class="stat"><span>Баланс</span><strong><?= h((string) ($status['balance_usdt'] ?? ($published['execution']['starting_balance_usdt'] ?? '—'))) ?> USDT</strong></div>
<div class="stat"><span>Реализованный результат</span><strong><?= h((string) ($status['realized_pnl_usdt'] ?? '0')) ?> USDT</strong></div>
<div class="stat"><span>Плавающий результат</span><strong><?= h((string) ($status['unrealized_pnl_usdt'] ?? '0')) ?> USDT</strong></div>
<div class="stat"><span>Paper сделки</span><strong><?= h((string) ($status['trades'] ?? 0)) ?></strong></div>
</div><p class="note">Стратегия: <?= h((string) ($published['release_id'] ?? 'не опубликована')) ?> · PID <?= h((string) ($status['service']['pid'] ?? '—')) ?> · открытых позиций: <?= h((string) ($status['open_positions'] ?? 0)) ?></p>
<?php if (is_array($published)): ?>
<section class="published-summary" aria-label="Параметры опубликованной стратегии"><h3>Именно эту стратегию запустит кнопка ниже</h3>
<div class="grid">
<div class="stat"><span>Тикер</span><strong><?= h(implode(', ', array_map('strval', $published['market']['symbols'] ?? [])) ?: '—') ?></strong></div>
<div class="stat"><span>Условие входа</span><strong><?= h(entryDescription($published['strategy'] ?? [])) ?></strong></div>
<div class="stat"><span>Условие выхода</span><strong><?= h(exitDescription((string) ($published['strategy']['exit_config'] ?? ''))) ?></strong></div>
<div class="stat"><span>Период проверки стратегии</span><strong><?= h((string) ($published['backtest']['start_date'] ?? '—')) ?> — <?= h((string) ($published['backtest']['end_date'] ?? '—')) ?></strong></div>
<div class="stat"><span>Виртуальный стартовый баланс / лимит ордера</span><strong><?= h((string) ($published['execution']['starting_balance_usdt'] ?? '—')) ?> / <?= h((string) ($published['risk']['max_order_notional_usdt'] ?? '—')) ?> USDT</strong></div>
<div class="stat"><span>Максимальная общая позиция</span><strong><?= h((string) ($published['risk']['max_total_exposure_usdt'] ?? '—')) ?> USDT</strong></div>
<div class="stat"><span>Максимальный убыток за день</span><strong><?= h((string) ($published['risk']['max_daily_loss_usdt'] ?? '—')) ?> USDT</strong></div>
</div>
<?php if ($selectedStrategyPresent && (($published['market']['category'] ?? '') !== $category || ($published['market']['symbols'][0] ?? '') !== $symbol || ($published['strategy']['entry_config'] ?? '') !== $entryConfig || ($published['strategy']['entry_signal_mode'] ?? '') !== $entrySignalMode || ($published['strategy']['entry_direction_mode'] ?? '') !== $entryDirectionMode || ($published['strategy']['exit_config'] ?? '') !== $exitConfig || ($published['strategy']['martingale_mode'] ?? '') !== $martingaleMode || (bool) ($published['execution']['include_funding'] ?? false) !== $includeFunding || ($published['strategy']['exit_confirmation_mode'] ?? '') !== $exitConfirmationMode || (string) ($published['strategy']['exit_confirmation_snapshots'] ?? '') !== (string) $exitConfirmationSnapshots || ($published['backtest']['start_date'] ?? '') !== $startDate || ($published['backtest']['end_date'] ?? '') !== $endDate)): ?><p class="status warning">В «Базовых проверках» сейчас выбрана другая конфигурация. Кнопка запуска использует опубликованную выше; чтобы применить текущий выбор, сначала опубликуйте его ниже.</p><?php endif; ?>
</section>
<?php else: ?><p class="status warning">Стратегия ещё не опубликована. Сначала выберите и опубликуйте её в блоке ниже.</p><?php endif; ?>
<div class="actions">
<form method="post"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="start"><button <?= !$isLocal || !is_array($published) || ($status['service']['running'] ?? false) ? 'disabled' : '' ?>><?= ($status['service']['running'] ?? false) ? 'Paper уже запущен' : 'Запустить опубликованную стратегию' ?></button></form>
<form method="post"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="stop"><button class="secondary" <?= !$isLocal || !($status['service']['running'] ?? false) ? 'disabled' : '' ?>>Остановить процесс</button></form>
<form method="post"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="<?= ($status['controls']['entries_paused'] ?? false) ? 'resume' : 'pause' ?>"><button class="secondary" <?= !$isLocal || !($status['service']['running'] ?? false) ? 'disabled' : '' ?>><?= ($status['controls']['entries_paused'] ?? false) ? 'Возобновить новые входы' : 'Приостановить новые входы' ?></button></form>
<form method="post" onsubmit="return confirm('Закрыть все открытые paper позиции по ближайшим свежим котировкам?')"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="close"><button class="danger" <?= !$isLocal || !($status['service']['running'] ?? false) || ($status['open_positions'] ?? 0) < 1 ? 'disabled' : '' ?>>Закрыть paper позиции</button></form>
</div><p class="note">«Остановить процесс» сохраняет состояние и оставляет виртуальные позиции открытыми. «Приостановить новые входы» оставляет работающий процесс и управление текущей позицией. Закрытие выполняется по следующей свежей котировке.</p></section>

<section class="panel"><h2>Сверка Bitget Demo</h2>
<p class="note">Запрос только читает Demo аккаунт и сравнивает его с пустым локальным журналом Demo. Paper позиции хранятся отдельно. Если на бирже есть открытая позиция или ордер, он будет показан как расхождение. Проверка не отправляет и не закрывает ордера.</p>
<form method="post"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="demo-reconcile"><input type="hidden" name="symbol" value="<?= h($symbol) ?>"><button class="secondary" <?= !$isLocal ? 'disabled' : '' ?>>Проверить Demo аккаунт · <?= h($symbol) ?></button></form>
<?php if (is_array($demoReconciliation)): ?>
<p class="status <?= !empty($demoReconciliation['matched']) ? 'success' : 'warning' ?>" role="status">
<?= !empty($demoReconciliation['matched']) ? 'Совпадает' : 'Нужно проверить' ?> · проверено <?= h((string) ($demoReconciliation['checked_at_utc'] ?? '—')) ?> UTC
<?php if (isset($demoReconciliation['pending_orders'])): ?> · позиций: <?= h((string) count($demoReconciliation['positions'] ?? [])) ?> · открытых ордеров: <?= h((string) $demoReconciliation['pending_orders']) ?> · fills: <?= h((string) ($demoReconciliation['fills'] ?? 0)) ?><?php endif; ?>
</p>
<?php if (!empty($demoReconciliation['error'])): ?><p class="status warning"><?= h((string) $demoReconciliation['error']) ?></p><?php endif; ?>
<?php foreach (($demoReconciliation['discrepancies'] ?? []) as $discrepancy): ?><p class="status warning"><?= h((string) $discrepancy) ?></p><?php endforeach; ?>
<?php if (($demoReconciliation['positions'] ?? []) !== []): ?><div class="table-wrap"><table><thead><tr><th>Тикер</th><th>Сторона</th><th>Количество</th><th>Средняя цена</th></tr></thead><tbody><?php foreach ($demoReconciliation['positions'] as $position): ?><tr><td><?= h((string) ($position['symbol'] ?? '—')) ?></td><td><?= h((string) ($position['side'] ?? '—')) ?></td><td><?= h((string) ($position['quantity'] ?? '—')) ?></td><td><?= h((string) ($position['average_entry_price'] ?? '—')) ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
<?php endif; ?></section>

<section class="panel"><h2>Публикация выбранной стратегии</h2>
<?php if (!$selectedStrategyPresent): ?><p class="empty">Сначала выберите тикер и стратегию в разделе «Базовые проверки», затем откройте «Автоторговля».</p><a href="./">Открыть базовые проверки</a>
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
</form><?php endif; ?></section>

<section class="panel"><h2>Открытые позиции</h2><?php if (($status['positions'] ?? []) === []): ?><p class="empty">Открытых виртуальных позиций нет.</p><?php else: ?><div class="table-wrap"><table><thead><tr><th>Тикер</th><th>Сторона</th><th>Количество</th><th>Вход</th><th>Плавающий PnL · USDT</th><th>Последняя котировка · Киев</th></tr></thead><tbody><?php foreach ($status['positions'] as $position): ?><tr><td><?= h((string) $position['symbol']) ?></td><td><?= h((string) $position['side']) ?></td><td><?= h((string) $position['quantity']) ?></td><td><?= h((string) $position['entry_price']) ?></td><td><?= h((string) ($position['unrealized_pnl_usdt'] ?? '—')) ?></td><td><?= h(timeMsLabel($position['quote_timestamp_ms'] ?? null)) ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></section>

<section class="panel"><h2>Последние paper сделки</h2><?php if (($status['recent_trades'] ?? []) === []): ?><p class="empty">Сделок пока нет.</p><?php else: ?><div class="table-wrap"><table><thead><tr><th>Тикер</th><th>Сторона</th><th>Количество</th><th>Вход · Киев</th><th>Выход · Киев</th><th>Результат · USDT</th></tr></thead><tbody><?php foreach ($status['recent_trades'] as $trade): ?><tr><td><?= h((string) $trade['symbol']) ?></td><td><?= h((string) $trade['side']) ?></td><td><?= h((string) $trade['quantity']) ?></td><td><?= h(timeMsLabel($trade['entry_ts'] ?? null)) ?></td><td><?= h(timeMsLabel($trade['exit_ts'] ?? null)) ?></td><td><?= h((string) $trade['pnl']) ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></section>

<section class="panel"><h2>События и ошибки</h2><?php if (($status['recent_events'] ?? []) === []): ?><p class="empty">Событий пока нет.</p><?php else: ?><div class="table-wrap"><table><thead><tr><th>Время · Киев</th><th>Тип</th><th>Тикер</th><th>Данные</th></tr></thead><tbody><?php foreach ($status['recent_events'] as $event): ?><tr><td><?= h(timeMsLabel($event['timestamp_ms'] ?? null)) ?></td><td><?= h((string) $event['type']) ?></td><td><?= h((string) ($event['symbol'] ?? '—')) ?></td><td><?= h(json_encode($event['payload'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '') ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></section>
<p class="note">Автообновления нет: перезагрузите страницу после команды или дождитесь следующей задачи интерфейса, если понадобится live обновление.</p>
</main></body></html>
