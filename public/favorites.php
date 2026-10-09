<?php
declare(strict_types=1);

date_default_timezone_set('Europe/Kyiv');
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
    $type = (string) ($params['favorite_type'] ?? 'strategy');
    $entryLabel = trim((string) ($params['favorite_entry_label'] ?? ''));
    $exitLabel = trim((string) ($params['favorite_exit_label'] ?? ''));
    $favoriteName = trim((string) ($params['favorite_name'] ?? ''));
    unset($params['favorite_type'], $params['favorite_entry_label'], $params['favorite_exit_label'], $params['favorite_name'], $params['favorite_action']);
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

$favorites = array_reverse(readFavorites($storePath));
?><!doctype html>
<html lang="ru">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Избранное · Bitget</title>
<style>
:root{color-scheme:dark;--bg:#0b1020;--panel:#131a2d;--line:#273149;--text:#e9eefb;--muted:#8995ad}*{box-sizing:border-box}body{margin:0;background:radial-gradient(ellipse at 15% 0%,#1b2850 0,transparent 45%),var(--bg);color:var(--text);font:15px/1.5 system-ui,-apple-system,Segoe UI,sans-serif}.wrap{max-width:1200px;margin:0 auto;padding:42px 24px}.nav{display:flex;gap:8px;margin-bottom:20px}.nav a{color:#b7c1d5;text-decoration:none;padding:9px 14px;border:1px solid var(--line);border-radius:8px;background:#0c1222}.panel{background:var(--panel);border:1px solid var(--line);border-radius:18px;padding:22px;overflow:auto}h1{margin-top:0}.empty{color:var(--muted)}table{width:100%;border-collapse:collapse;min-width:780px}th,td{text-align:left;padding:12px 10px;border-bottom:1px solid var(--line);vertical-align:top}th{color:#aab5cb;font-size:12px;text-transform:uppercase}tbody tr:hover{background:#1a2540}a{color:#b9c7ff}.muted{color:var(--muted);font-size:13px}
</style></head><body><main class="wrap">
<nav class="nav"><a href="./">Базовые проверки</a><a href="hedging.php">Хеджирование</a><a href="favorites.php" aria-current="page">Избранное</a></nav>
<section class="panel"><h1>Избранное</h1>
<?php if ($favorites === []): ?><p class="empty">Сохранённых стратегий пока нет. Добавьте текущую стратегию кнопкой «Добавить в избранное».</p>
<?php else: ?><table><thead><tr><th>Тикер / пара</th><th>Стратегия входа</th><th>Стратегия выхода</th><th>Баланс</th><th>Комиссия</th><th>Добавлено</th></tr></thead><tbody>
<?php foreach ($favorites as $favorite): $params = is_array($favorite['params'] ?? null) ? $favorite['params'] : []; $type = (string) ($favorite['type'] ?? 'strategy'); $favoriteId = (string) ($favorite['id'] ?? ''); $route = $type === 'hedge' ? 'hedging.php?' : './?'; $href = $route . http_build_query(array_merge($params, ['favorite_id' => $favoriteId])); $tickerLabel = $type === 'hedge' ? 'Лонг ' . (string) ($params['long_symbol'] ?? '—') . ' / шорт ' . (string) ($params['short_symbol'] ?? '—') : (($type === 'global_top' ? 'Топ-5 · ' : '') . (string) ($params['symbol'] ?? '—')); $entryCell = $type === 'hedge' ? 'Совместный лонг / шорт' : (string) ($favorite['entry_label'] ?? $params['entry_config'] ?? '—'); $exitCell = $type === 'hedge' ? 'Закрытие по последнему снимку' : (string) ($favorite['exit_label'] ?? $params['exit_config'] ?? '—'); ?>
<tr><td><a href="<?= h($href) ?>"><strong><?= h($tickerLabel) ?></strong></a><div class="muted"><?= h((string) ($favorite['name'] ?? '')) ?></div></td><td><?= h($entryCell) ?></td><td><?= h($exitCell) ?></td><td><?= h((string) ($params['balance'] ?? '—')) ?> USDT</td><td><?= h((string) ($params['fee'] ?? '—')) ?>%</td><td class="muted"><?= h((string) ($favorite['created_at'] ?? '')) ?></td></tr>
<?php endforeach; ?></tbody></table><?php endif; ?></section></main></body></html>
