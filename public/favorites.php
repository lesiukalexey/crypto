<?php
declare(strict_types=1);

date_default_timezone_set('Europe/Kyiv');
require_once __DIR__ . '/ticker-settings-lib.php';
$config = json_decode((string) file_get_contents(dirname(__DIR__) . '/config.json'), true, 512, JSON_THROW_ON_ERROR);
$enabledTickerLookup = array_fill_keys(enabledTickerSymbols($config), true);
$storePath = (getenv('HOME') ?: '/home/alex') . '/.ai/home/.local/bitget-favorites.json';
$storeDir = dirname($storePath);

function favoriteJsonResponse(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function readFavorites(string $path): array
{
    if (!is_file($path)) return [];
    $decoded = json_decode((string) file_get_contents($path), true);
    return is_array($decoded) ? array_values(array_filter($decoded, 'is_array')) : [];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $params = $_POST;
    if (($params['favorite_action'] ?? '') === 'remove') {
        $favoriteId = trim((string) ($params['favorite_id'] ?? ''));
        if ($favoriteId === '') {
            favoriteJsonResponse(['status' => 'error', 'error' => 'Не выбрана запись избранного.'], 422);
        }
        $handle = fopen($storePath, 'c+');
        if ($handle === false || !flock($handle, LOCK_EX)) {
            if (is_resource($handle)) fclose($handle);
            favoriteJsonResponse(['status' => 'error', 'error' => 'Не удалось изменить избранное.'], 500);
        }
        rewind($handle);
        $existing = json_decode((string) stream_get_contents($handle), true);
        $favorites = is_array($existing) ? array_values(array_filter($existing, 'is_array')) : [];
        $remaining = array_values(array_filter(
            $favorites,
            static fn (array $favorite): bool => (string) ($favorite['id'] ?? '') !== $favoriteId,
        ));
        if (count($remaining) === count($favorites)) {
            flock($handle, LOCK_UN);
            fclose($handle);
            favoriteJsonResponse(['status' => 'error', 'error' => 'Запись избранного не найдена.'], 404);
        }
        if (!ftruncate($handle, 0)) {
            flock($handle, LOCK_UN);
            fclose($handle);
            favoriteJsonResponse(['status' => 'error', 'error' => 'Не удалось сохранить избранное.'], 500);
        }
        rewind($handle);
        $payload = json_encode($remaining, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
        $written = fwrite($handle, $payload);
        $flushed = fflush($handle);
        flock($handle, LOCK_UN);
        fclose($handle);
        favoriteJsonResponse($written !== strlen($payload) || !$flushed
            ? ['status' => 'error', 'error' => 'Не удалось сохранить избранное.']
            : ['status' => 'ok']);
    }
    if (($params['favorite_action'] ?? '') === 'update_result') {
        $favoriteId = trim((string) ($params['favorite_id'] ?? ''));
        $result = trim((string) ($params['favorite_result'] ?? ''));
        $marketAverage = trim((string) ($params['favorite_market_average'] ?? ''));
        $resultIsNumeric = preg_match('/^[+-]?\d+(?:\.\d+)?$/', $result) === 1;
        $averageIsNumeric = preg_match('/^[+-]?\d+(?:\.\d+)?$/', $marketAverage) === 1;
        if ($favoriteId === '' || (!$resultIsNumeric && !$averageIsNumeric)) {
            favoriteJsonResponse(['status' => 'error', 'error' => 'Некорректный результат избранного.'], 422);
        }
        $handle = fopen($storePath, 'c+');
        if ($handle === false || !flock($handle, LOCK_EX)) {
            if (is_resource($handle)) fclose($handle);
            favoriteJsonResponse(['status' => 'error', 'error' => 'Не удалось обновить избранное.'], 500);
        }
        rewind($handle);
        $existing = json_decode((string) stream_get_contents($handle), true);
        $favorites = is_array($existing) ? array_values(array_filter($existing, 'is_array')) : [];
        $updated = false;
        foreach ($favorites as &$favorite) {
            $type = (string) ($favorite['type'] ?? 'strategy');
            if ((string) ($favorite['id'] ?? '') !== $favoriteId || !in_array($type, ['global_top', 'ticker_leader'], true)) continue;
            if ($resultIsNumeric) {
                $favorite['result'] = $result;
                $favorite['result_label'] = 'Итог тикера';
            }
            if ($type === 'ticker_leader' && $averageIsNumeric) {
                $favorite['market_average'] = $marketAverage;
                $favorite['market_average_label'] = 'Средний итог этой пары на рынке';
            }
            $updated = true;
            break;
        }
        unset($favorite);
        if ($updated) {
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, json_encode($favorites, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
            fflush($handle);
        }
        flock($handle, LOCK_UN);
        fclose($handle);
        favoriteJsonResponse($updated ? ['status' => 'ok'] : ['status' => 'error', 'error' => 'Запись избранного не найдена.'], $updated ? 200 : 404);
    }
    $type = (string) ($params['favorite_type'] ?? 'strategy');
    $entryLabel = trim((string) ($params['favorite_entry_label'] ?? ''));
    $exitLabel = trim((string) ($params['favorite_exit_label'] ?? ''));
    $favoriteName = trim((string) ($params['favorite_name'] ?? ''));
    $favoriteResult = trim((string) ($params['favorite_result'] ?? ''));
    $favoriteResultLabel = trim((string) ($params['favorite_result_label'] ?? ''));
    $marketAverage = trim((string) ($params['favorite_market_average'] ?? ''));
    $marketAverageLabel = trim((string) ($params['favorite_market_average_label'] ?? ''));
    $rankingEntryConfig = trim((string) ($params['favorite_ranking_entry_config'] ?? ''));
    unset($params['favorite_type'], $params['favorite_entry_label'], $params['favorite_exit_label'], $params['favorite_name'], $params['favorite_result'], $params['favorite_result_label'], $params['favorite_market_average'], $params['favorite_market_average_label'], $params['favorite_ranking_entry_config'], $params['favorite_action']);
    if (!in_array($type, ['strategy', 'global_top', 'ticker_leader', 'hedge'], true)) {
        favoriteJsonResponse(['status' => 'error', 'error' => 'Неизвестный тип избранного.'], 422);
    }
    $isHedge = $type === 'hedge';
    if ($isHedge) {
        if (($params['long_symbol'] ?? '') === '' || ($params['short_symbol'] ?? '') === '' || $params['long_symbol'] === $params['short_symbol']) {
            favoriteJsonResponse(['status' => 'error', 'error' => 'Не выбрана корректная пара лонг/шорт.'], 422);
        }
    } elseif (($params['symbol'] ?? '') === '' || ($params['entry_config'] ?? '') === '' || ($params['exit_config'] ?? '') === '') {
        favoriteJsonResponse(['status' => 'error', 'error' => 'Не хватает параметров стратегии.'], 422);
    }
    if (!is_dir($storeDir) && !mkdir($storeDir, 0700, true) && !is_dir($storeDir)) {
        favoriteJsonResponse(['status' => 'error', 'error' => 'Не удалось создать хранилище избранного.'], 500);
    }
    $handle = fopen($storePath, 'c+');
    if ($handle === false || !flock($handle, LOCK_EX)) {
        if (is_resource($handle)) fclose($handle);
        favoriteJsonResponse(['status' => 'error', 'error' => 'Не удалось сохранить избранное.'], 500);
    }
    rewind($handle);
    $existing = json_decode((string) stream_get_contents($handle), true);
    $favorites = is_array($existing) ? array_values(array_filter($existing, 'is_array')) : [];
    $id = 'fav_' . bin2hex(random_bytes(8));
    $defaultName = $isHedge
        ? 'Лонг ' . $params['long_symbol'] . ' / шорт ' . $params['short_symbol']
        : (string) $params['symbol'] . ' · ' . ($entryLabel !== '' ? $entryLabel : (string) $params['entry_config']);
    $favorite = [
        'id' => $id,
        'type' => $type,
        'name' => $favoriteName !== '' ? $favoriteName : $defaultName,
        'entry_label' => $entryLabel,
        'exit_label' => $exitLabel,
        'result' => $type === 'global_top' ? null : (preg_match('/^[+-]?\d+(?:\.\d+)?$/', $favoriteResult) ? $favoriteResult : null),
        'result_label' => $favoriteResultLabel,
        'market_average' => preg_match('/^[+-]?\d+(?:\.\d+)?$/', $marketAverage) ? $marketAverage : null,
        'market_average_label' => $marketAverageLabel,
        'ranking_entry_config' => $rankingEntryConfig,
        'created_at' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
        'params' => $params,
    ];
    $favorites[] = $favorite;
    ftruncate($handle, 0);
    rewind($handle);
    fwrite($handle, json_encode($favorites, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
    fflush($handle);
    flock($handle, LOCK_UN);
    fclose($handle);
    favoriteJsonResponse(['status' => 'ok', 'id' => $id]);
}

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function favoriteParameterLabel(string $name): string
{
    static $labels = [
        'ticker_set' => 'Список тикеров', 'symbol' => 'Торговая пара', 'start_day' => 'Начало периода', 'end_day' => 'Конец периода',
        'entry_signal_mode' => 'Тип сигнала входа', 'entry_direction_mode' => 'Направление входа', 'entry_config' => 'Порог входа · ID',
        'exit_confirmation_mode' => 'Тип подтверждения выхода', 'exit_confirmation_snapshots' => 'Интервалы подтверждения выхода', 'exit_config' => 'Take Profit / Stop Loss · ID',
        'balance' => 'Стартовый баланс, USDT', 'fee' => 'Комиссия за сторону, %', 'slippage_bps' => 'Проскальзывание, б.п.', 'include_funding' => 'Учитывать funding',
        'martingale_mode' => 'Режим мартингейла', 'martingale_timing' => 'Момент следующего шага', 'martingale_attempts' => 'Попытки мартингейла',
        'martingale_max_chain_loss' => 'Лимит убытка цепочки, USDT', 'martingale_max_day_loss' => 'Лимит убытка за день, USDT',
        'strategy_max_day_loss_usdt' => 'Лимит убытка стратегии за день, USDT', 'martingale_target_policy' => 'Политика цели мартингейла',
        'threshold_mode' => 'Режим порога', 'window_mode' => 'Режим окна', 'fixed_grid_mode' => 'Режим сетки',
        'profit_ranges' => 'Диапазоны Take Profit', 'loss_ranges' => 'Диапазоны Stop Loss', 'save_mode' => 'SAVE MODE',
        'start_day_auto' => 'Автовыбор начальной даты', 'balance_auto' => 'Автовыбор баланса', 'ranking_balance' => 'Баланс общего рейтинга, USDT',
        'entry_mode' => 'Режим входа', 'martingale_max_chain_loss' => 'Лимит убытка цепочки, USDT',
    ];
    return $labels[$name] ?? $name;
}

function favoriteParameterValue(mixed $value): string
{
    if (is_bool($value)) return $value ? 'Да' : 'Нет';
    if (is_array($value)) return (string) (json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]');
    return trim((string) $value);
}


$favorites = array_reverse(readFavorites($storePath));
?><!doctype html>
<html lang="ru">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Избранное · Bitget</title>
<style>
:root{color-scheme:dark;--bg:#0b1020;--panel:#131a2d;--line:#273149;--text:#e9eefb;--muted:#8995ad}*{box-sizing:border-box}body{margin:0;background:radial-gradient(ellipse at 15% 0%,#1b2850 0,transparent 45%),var(--bg);color:var(--text);font:15px/1.5 system-ui,-apple-system,Segoe UI,sans-serif}.wrap{max-width:1400px;margin:0 auto;padding:42px 24px}.nav{display:flex;gap:8px;margin-bottom:20px}.nav a{color:#b7c1d5;text-decoration:none;padding:9px 14px;border:1px solid var(--line);border-radius:8px;background:#0c1222}.panel{background:var(--panel);border:1px solid var(--line);border-radius:18px;padding:22px;overflow:auto}h1{margin-top:0}.empty{color:var(--muted)}table{width:100%;border-collapse:collapse;min-width:950px}th,td{text-align:left;padding:12px 10px;border-bottom:1px solid var(--line);vertical-align:top}th{color:#aab5cb;font-size:12px;text-transform:uppercase}tbody tr:hover{background:#1a2540}a{color:#b9c7ff}.muted{color:var(--muted);font-size:13px}.add-autotrading{display:inline-flex;align-items:center;justify-content:center;margin:0 0 8px;padding:8px 12px;border:1px solid #5574d8;border-radius:8px;background:linear-gradient(180deg,#344d9d,#263b7b);color:#fff;text-decoration:none;font-weight:600;white-space:nowrap;box-shadow:0 3px 12px #17255466;transition:background .15s,border-color .15s,transform .15s}.add-autotrading:hover{background:linear-gradient(180deg,#4261bd,#304a99);border-color:#8299ef;transform:translateY(-1px)}.add-autotrading:focus-visible{outline:2px solid #b6c5ff;outline-offset:2px}.favorite-autotrading-actions{display:flex;flex-direction:column;align-items:stretch;gap:7px;min-width:128px}.favorite-autotrading-actions .add-autotrading,.favorite-autotrading-actions .remove-favorite{width:100%;min-width:128px;min-height:36px;margin:0;padding:7px 9px;font-size:13px}.add-autotrading.mode-paper{border-color:#39785e;background:linear-gradient(180deg,#286449,#1d4936)}.add-autotrading.mode-paper:hover{background:linear-gradient(180deg,#347d5c,#286449)}.add-autotrading.mode-demo{border-color:#5574d8}.add-autotrading.mode-real{border-color:#9a5964;background:linear-gradient(180deg,#783945,#572a33)}.add-autotrading.mode-real:hover{background:linear-gradient(180deg,#944856,#71343e)}.favorite-parameter-details{min-width:180px}.favorite-parameter-details summary{cursor:pointer;color:#b9c7ff;font-weight:600}.favorite-params{display:grid;grid-template-columns:minmax(90px,auto) minmax(120px,1fr);gap:3px 10px;max-width:520px;margin:10px 0 0;font-size:12px}.favorite-params dt{color:var(--muted);overflow-wrap:anywhere}.favorite-params dd{margin:0;overflow-wrap:anywhere}.remove-favorite{display:block;border:1px solid #713844;border-radius:8px;background:#381c29;color:#ffd9df;font:600 13px/1.3 system-ui,-apple-system,Segoe UI,sans-serif;white-space:nowrap;cursor:pointer}.remove-favorite:hover{background:#592632}.remove-favorite:focus-visible{outline:2px solid #ffc2cc;outline-offset:2px}.remove-favorite:disabled{opacity:.6;cursor:wait}.favorite-remove-status{display:block;min-width:120px;margin-top:0;color:#ffb6c1;font-size:12px}
</style></head><body><main class="wrap">
<nav class="nav"><a href="index.php">Главная</a><a href="basic-checks.php">Базовые проверки</a><a href="hedging.php">Хеджирование</a><a href="favorites.php" aria-current="page">Избранное</a><a href="settings.php">Настройки</a><a href="autotrading.php?mode=paper">Автоторговля (Paper)</a><a href="autotrading.php?mode=demo">Автоторговля (Тест)</a><a href="autotrading.php?mode=real">Автоторговля (реал)</a><a href="autotrading-pi.php">Raspberry Pi</a></nav>
<section class="panel"><h1>Избранное</h1>
<?php if ($favorites === []): ?><p class="empty">В избранном пока пусто. Добавьте стратегию или пару из разделов приложения.</p>
<?php else: ?><table><thead><tr><th>Тикер / пара</th><th>Стратегия входа</th><th>Стратегия выхода</th><th>Параметры стратегии</th><th>Доходность за весь доступный интервал</th><th>Добавлено</th><th>Действие</th></tr></thead><tbody>
<?php foreach ($favorites as $favorite): $params = is_array($favorite['params'] ?? null) ? $favorite['params'] : []; $displayParams = array_diff_key($params, ['balance' => true, 'fee' => true]); $type = (string) ($favorite['type'] ?? 'strategy'); $favoriteId = (string) ($favorite['id'] ?? ''); $route = $type === 'hedge' ? 'hedging.php?' : './?'; $favoriteSymbols = $type === 'hedge' ? [strtoupper((string) ($params['long_symbol'] ?? '')), strtoupper((string) ($params['short_symbol'] ?? ''))] : [strtoupper((string) ($params['symbol'] ?? ''))]; $favoriteTickerEnabled = !in_array('', $favoriteSymbols, true) && count(array_filter($favoriteSymbols, static fn (string $symbol): bool => isset($enabledTickerLookup[$symbol]))) === count($favoriteSymbols); $href = $favoriteTickerEnabled ? $route . http_build_query(array_merge($params, ['favorite_id' => $favoriteId])) : ''; $tickerLabel = $type === 'hedge' ? 'Лонг ' . (string) ($params['long_symbol'] ?? '—') . ' / шорт ' . (string) ($params['short_symbol'] ?? '—') : (($type === 'global_top' ? 'Топ-5 · ' : '') . (string) ($params['symbol'] ?? '—')); $entryCell = $type === 'hedge' ? 'Совместный лонг / шорт' : (string) ($favorite['entry_label'] ?? $params['entry_config'] ?? '—'); $exitCell = $type === 'hedge' ? 'Закрытие по последнему снимку' : (string) ($favorite['exit_label'] ?? $params['exit_config'] ?? '—'); $entryId = trim((string) ($params['entry_config'] ?? $favorite['ranking_entry_config'] ?? '')); $exitId = trim((string) ($params['exit_config'] ?? '')); $favoriteReturnEligible = in_array($type, ['strategy', 'global_top', 'ticker_leader'], true) && $entryId !== '' && $exitId !== ''; ?>
<tr><td><?php if ($favoriteTickerEnabled): ?><a href="<?= h($href) ?>"><strong><?= h($tickerLabel) ?></strong></a><?php else: ?><strong><?= h($tickerLabel) ?></strong><div class="muted">Выключен в настройках</div><?php endif; ?><div class="muted"><?= h((string) ($favorite['name'] ?? '')) ?></div></td><td><?= h($entryCell) ?></td><td><?= h($exitCell) ?></td><td><details class="favorite-parameter-details"><summary>Все параметры (<?= count($displayParams) ?>)</summary><dl class="favorite-params"><?php foreach ($displayParams as $parameterName => $parameterValue): ?><dt><?= h(favoriteParameterLabel((string) $parameterName)) ?></dt><dd><?= h(favoriteParameterValue($parameterValue)) ?></dd><?php endforeach; ?></dl></details></td><td><?php if ($favoriteReturnEligible): ?><span class="favorite-return-value" data-favorite-return="<?= h($favoriteId) ?>">Загружаю рассчитанную доходность…</span><?php else: ?><span class="muted">Нет сохранённых точек для этой записи</span><?php endif; ?></td><td class="muted"><?= h((string) ($favorite['created_at'] ?? '')) ?></td><td><div class="favorite-autotrading-actions"><?php if ($favoriteTickerEnabled): ?><a class="add-autotrading mode-paper" href="autotrading.php?mode=paper&amp;favorite_id=<?= rawurlencode($favoriteId) ?>">Paper</a><a class="add-autotrading mode-demo" href="autotrading.php?mode=demo&amp;favorite_id=<?= rawurlencode($favoriteId) ?>">Bitget Demo</a><a class="add-autotrading mode-real" href="autotrading.php?mode=real&amp;favorite_id=<?= rawurlencode($favoriteId) ?>">Bitget Real</a><?php endif; ?><button type="button" class="remove-favorite" data-favorite-id="<?= h($favoriteId) ?>">Удалить из избранного</button><span class="favorite-remove-status" aria-live="polite"></span></div></td></tr>
<?php endforeach; ?></tbody></table><?php endif; ?></section>
<script>
document.querySelectorAll('.remove-favorite').forEach(button => {
    button.addEventListener('click', async () => {
        if (!window.confirm('Удалить эту запись из избранного?')) return;
        const status = button.parentElement.querySelector('.favorite-remove-status');
        button.disabled = true;
        button.textContent = 'Удаляю…';
        status.textContent = '';
        try {
            const body = new URLSearchParams({favorite_action: 'remove', favorite_id: button.dataset.favoriteId});
            const response = await fetch('favorites.php', {method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded', Accept: 'application/json'}, body});
            const result = await response.json();
            if (!response.ok || result.status !== 'ok') throw new Error(result.error || 'Не удалось удалить запись');
            const row = button.closest('tr');
            row.remove();
            if (!document.querySelector('tbody tr')) location.reload();
        } catch (error) {
            button.disabled = false;
            button.textContent = 'Удалить из избранного';
            status.textContent = error.message || 'Не удалось удалить запись';
        }
    });
});
document.querySelectorAll('[data-favorite-return]').forEach(async cell => {
    try {
        const query = new URLSearchParams({favorite_id: cell.dataset.favoriteReturn});
        const response = await fetch('favorite-return-data.php?' + query.toString(), {headers: {Accept: 'application/json'}, cache: 'no-store'});
        const result = await response.json();
        if (!response.ok || result.status === 'error') throw new Error(result.message || 'Не удалось загрузить доходность');
        if (result.status !== 'ready') {
            cell.textContent = 'Нет рассчитанной истории доходности';
            return;
        }
        const value = Number(result.return_percent);
        cell.textContent = (value > 0 ? '+' : '') + new Intl.NumberFormat('ru-RU', {minimumFractionDigits: 2, maximumFractionDigits: 2}).format(value) + '%';
        const details = document.createElement('span');
        details.className = 'muted';
        details.textContent = result.start_at + ' — ' + result.end_at + ' · ' + result.closed_trade_count + ' закрытых сделок · Киевское время' + (result.provisional ? ' · последняя точка предварительная' : '');
        cell.append(details);
    } catch (error) {
        cell.textContent = error.message || 'Не удалось загрузить доходность';
    }
});
</script></main></body></html>
