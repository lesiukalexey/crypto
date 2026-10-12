<?php
declare(strict_types=1);

session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict', 'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off']);
session_start();
header('Cache-Control: no-store');

function h(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function runPiCommand(array $arguments, string $root, string $python, string $target): array {
    $process = proc_open(array_merge([$python, '-m', 'autotrading'], $arguments), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, [
        'PYTHONPATH' => $root,
        'BITGET_PI_SSH_TARGET' => $target,
    ]);
    if (!is_resource($process)) throw new RuntimeException('Не удалось запустить локальный SSH исполнитель.');
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]); fclose($pipes[2]);
    return [proc_close($process), trim((string) $stdout), trim((string) $stderr)];
}

$root = dirname(__DIR__);
$uid = function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();
$runtimeRoot = getenv('BITGET_AUTOTRADING_DIR') ?: '/var/tmp';
$runtime = rtrim($runtimeRoot, '/') . '/bitget-autotrading-' . $uid;
if (!is_dir($runtime) && !mkdir($runtime, 0700, true) && !is_dir($runtime)) throw new RuntimeException('Не удалось создать каталог локального состояния.');
if (is_link($runtime) || fileowner($runtime) !== $uid) throw new RuntimeException('Каталог локального состояния имеет неподходящего владельца.');
chmod($runtime, 0700);
$activeReleasePath = $runtime . '/active-release.json';
$python = getenv('BITGET_AUTOTRADING_PYTHON') ?: (is_executable($root . '/.venv/bin/python') ? $root . '/.venv/bin/python' : '/usr/bin/python3');
if (!is_executable($python)) $python = 'python3';
$target = trim((string) (getenv('BITGET_PI_SSH_TARGET') ?: ''));
$isLocal = in_array((string) ($_SERVER['REMOTE_ADDR'] ?? ''), ['127.0.0.1', '::1'], true);
if (!isset($_SESSION['autotrading_pi_csrf'])) $_SESSION['autotrading_pi_csrf'] = bin2hex(random_bytes(32));
$csrf = (string) $_SESSION['autotrading_pi_csrf'];
$message = '';
$messageType = 'info';
$report = is_array($_SESSION['autotrading_pi_report'] ?? null) ? $_SESSION['autotrading_pi_report'] : null;
$staged = is_array($_SESSION['autotrading_pi_staged'] ?? null) ? $_SESSION['autotrading_pi_staged'] : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!$isLocal) throw new RuntimeException('Управление Raspberry Pi доступно только с этого компьютера.');
        if (!hash_equals($csrf, (string) ($_POST['csrf'] ?? ''))) throw new RuntimeException('Сессия формы истекла. Обновите страницу.');
        if ($target === '') throw new RuntimeException('Задайте BITGET_PI_SSH_TARGET в окружении локального web-сервера.');
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'refresh') {
            [$code, $out, $err] = runPiCommand(['pi-status', '--target', $target], $root, $python, $target);
            $result = json_decode($out, true);
            if ($code !== 0 || !is_array($result) || ($result['mode'] ?? null) !== 'paper') throw new RuntimeException($err ?: 'Не удалось получить состояние с Raspberry Pi.');
            $_SESSION['autotrading_pi_report'] = $result;
            $report = $result;
            $message = 'Статус Raspberry Pi обновлён по SSH.';
            $messageType = 'success';
        } elseif ($action === 'stage-release') {
            if (!is_file($activeReleasePath)) throw new RuntimeException('Сначала опубликуйте стратегию в разделе «Автоторговля».');
            [$code, $out, $err] = runPiCommand(['pi-stage-release', '--target', $target, '--release', $activeReleasePath], $root, $python, $target);
            $result = json_decode($out, true);
            if ($code !== 0 || !is_array($result) || empty($result['staged'])) throw new RuntimeException($err ?: 'Raspberry Pi не подтвердил передачу стратегии.');
            $_SESSION['autotrading_pi_staged'] = $result;
            $staged = $result;
            $message = 'Стратегия передана и проверена на Raspberry Pi.';
            $messageType = 'success';
        } else throw new RuntimeException('Неизвестное действие.');
    } catch (Throwable $exception) {
        $message = $exception->getMessage();
        $messageType = 'error';
    }
}
if ($messageType === 'success') {
    $_SESSION['autotrading_pi_flash'] = [$message, $messageType];
    header('Location: autotrading-pi.php', true, 303);
    exit;
}
if ($message === '' && is_array($_SESSION['autotrading_pi_flash'] ?? null)) {
    [$message, $messageType] = $_SESSION['autotrading_pi_flash'];
    unset($_SESSION['autotrading_pi_flash']);
}
$paperState = is_array($report['state'] ?? null) ? $report['state'] : [];
?>
<!doctype html>
<html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Raspberry Pi · Автоторговля · Bitget</title>
<style>
:root{color-scheme:dark;--bg:#0b1020;--panel:#131a2d;--muted:#8995ad;--text:#e9eefb;--line:#273149}*{box-sizing:border-box}body{margin:0;background:radial-gradient(ellipse at 15% 0%,#1b2850 0,transparent 45%),var(--bg);color:var(--text);font:15px/1.5 system-ui,-apple-system,Segoe UI,sans-serif}.wrap{max-width:1200px;margin:auto;padding:30px 20px}h1{margin:12px 0;font-size:clamp(26px,4vw,38px)}h2{font-size:18px;margin:0 0 12px}.nav{display:flex;gap:6px;flex-wrap:wrap;margin:18px 0;padding:5px;border:1px solid var(--line);border-radius:12px;background:#0c1222;width:max-content;max-width:100%}.nav a{color:#b7c1d5;text-decoration:none;padding:8px 12px;border-radius:8px}.nav a[aria-current=page]{background:#263968;color:#fff}.panel{background:var(--panel);border:1px solid var(--line);border-radius:15px;padding:18px;margin:14px 0}.status{padding:11px 14px;border:1px solid var(--line);border-radius:10px}.status.error{color:#ffb3bf;border-color:#733b49}.status.success{color:#a8f0cf;border-color:#28654f}.status.warning{color:#ffe0a1}.note,.muted{color:var(--muted);font-size:13px}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px}.stat{padding:12px;border:1px solid var(--line);border-radius:10px;background:#0c1222}.stat span{display:block;color:var(--muted);font-size:12px}.stat strong{font-size:19px;font-variant-numeric:tabular-nums}button{padding:10px 14px;border:0;border-radius:8px;background:#607df4;color:white;font:inherit;font-weight:700;cursor:pointer}button.secondary{background:#34415e}button:disabled{opacity:.5;cursor:not-allowed}.actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:12px}.table-wrap{overflow:auto}table{width:100%;border-collapse:collapse;font-size:13px}td,th{text-align:left;padding:8px 9px;border-bottom:1px solid var(--line);white-space:nowrap}th{color:var(--muted)}code{color:#d5def5}@media(max-width:600px){.wrap{padding:18px 12px}.panel{padding:14px}.nav{width:100%}.nav a{flex:1;text-align:center}.actions button{flex:1}}
</style></head><body><main class="wrap">
<div class="muted">Bitget · удалённый paper исполнитель</div><h1>Raspberry Pi</h1>
<nav class="nav" aria-label="Разделы"><a href="index.php">Главная</a><a href="basic-checks.php">Базовые проверки</a><a href="hedging.php">Хеджирование</a><a href="favorites.php">Избранное</a><a href="settings.php">Настройки</a><a href="autotrading.php?mode=paper">Автоторговля (Paper)</a><a href="autotrading.php?mode=demo">Автоторговля (Тест)</a><a href="autotrading.php?mode=real">Автоторговля (реал)</a><a href="autotrading-pi.php" aria-current="page">Raspberry Pi</a></nav>
<?php if ($message !== ''): ?><p class="status <?= h($messageType) ?>" role="status"><?= h($message) ?></p><?php endif; ?>
<?php if (!$isLocal): ?><p class="status warning">Эта страница только для локального управления.</p><?php endif; ?>
<section class="panel"><h2>Связь с исполнителем</h2>
<p class="note">Статус и стратегия передаются только по SSH с проверкой ключа хоста. Сервер не открывает входящий HTTP-порт. Связь использует SSH target из локальной настройки.</p>
<?php if ($target === ''): ?><p class="status warning">Не настроено: задайте <code>BITGET_PI_SSH_TARGET</code> и SSH alias с ключом и проверенным known_hosts.</p><?php endif; ?>
<div class="actions">
<form method="post"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="refresh"><button class="secondary" <?= !$isLocal || $target === '' ? 'disabled' : '' ?>>Обновить статус с Raspberry Pi</button></form>
<form method="post" onsubmit="return confirm('Передать опубликованную paper-стратегию на Raspberry Pi? Передача блокируется, если на Pi есть открытые paper позиции.')"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="stage-release"><button <?= !$isLocal || $target === '' || !is_file($activeReleasePath) ? 'disabled' : '' ?>>Передать выбранную стратегию</button></form>
</div>
<?php if (is_array($report)): ?>
<p class="status <?= !empty($report['service_running']) ? 'success' : 'warning' ?>" role="status">Служба: <?= !empty($report['service_running']) ? 'работает' : 'остановлена' ?> · сверено <?= h((string) ($report['checked_at_utc'] ?? '—')) ?> UTC · стратегия: <?= h((string) ($report['active_release_id'] ?? 'не установлена')) ?></p>
<div class="grid">
<div class="stat"><span>Paper позиции</span><strong><?= h((string) ($paperState['open_positions'] ?? 0)) ?></strong></div>
<div class="stat"><span>Paper сделки</span><strong><?= h((string) ($paperState['trades'] ?? 0)) ?></strong></div>
<div class="stat"><span>Реализованный результат</span><strong><?= h((string) ($paperState['realized_pnl_usdt'] ?? '0')) ?> USDT</strong></div>
<div class="stat"><span>Плавающий результат</span><strong><?= h((string) ($paperState['unrealized_pnl_usdt'] ?? '0')) ?> USDT</strong></div>
</div>
<?php if (($paperState['positions'] ?? []) !== []): ?><div class="table-wrap"><table><thead><tr><th>Тикер</th><th>Сторона</th><th>Количество</th><th>Вход</th><th>Плавающий PnL</th></tr></thead><tbody><?php foreach ($paperState['positions'] as $position): ?><tr><td><?= h((string) $position['symbol']) ?></td><td><?= h((string) $position['side']) ?></td><td><?= h((string) $position['quantity']) ?></td><td><?= h((string) $position['entry_price']) ?></td><td><?= h((string) ($position['unrealized_pnl_usdt'] ?? '—')) ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
<?php endif; ?>
<?php if (is_array($staged)): ?><p class="status success">Стратегия <?= h((string) ($staged['release_id'] ?? '')) ?> staged; требуется перезапустить бумажный исполнитель на Pi: <code>systemctl --user restart bitget-autotrading.service</code>.</p><?php endif; ?>
</section>
<p class="note">Это отдельный paper runtime. Live adapter отключён и на Pi этой службой не используется.</p>
</main></body></html>
