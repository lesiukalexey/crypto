<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require_once __DIR__ . '/ticker-settings-lib.php';

function respond(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    respond(['status' => 'error', 'message' => 'Для удаления требуется POST-запрос.'], 405);
}

$origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
$host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
if ($origin !== '' && $host !== '' && strtolower((string) parse_url($origin, PHP_URL_HOST)) !== preg_replace('/:\d+$/', '', $host)) {
    respond(['status' => 'error', 'message' => 'Запрос на удаление рейтингов отклонён.'], 403);
}

try {
    $root = dirname(__DIR__);
    $config = json_decode((string) file_get_contents($root . '/config.json'), true, 512, JSON_THROW_ON_ERROR);
    $set = selectedTickerSet($config, trim((string) ($_POST['ticker_set'] ?? '')));
    if ($set['symbols'] === []) respond(['status' => 'error', 'message' => 'В выбранном списке нет тикеров.'], 400);

    $cacheDir = (getenv('HOME') ?: '/home/alex') . '/.ai/home/.local/bitget-backtest-cache';
    if (!is_dir($cacheDir)) respond(['status' => 'ready', 'deleted' => 0]);

    $expectedSymbols = array_values(array_map('strval', $set['symbols']));
    sort($expectedSymbols, SORT_STRING);
    $deleted = 0;
    $failed = false;
    foreach (glob($cacheDir . '/portfolio-*.json') ?: [] as $resultPath) {
        if (preg_match('/^portfolio-([a-f0-9]{64})\.json$/', basename($resultPath), $matches) !== 1) continue;
        $key = $matches[1];
        $lock = @fopen($cacheDir . '/portfolio-' . $key . '.lock', 'c');
        if ($lock === false) continue;
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            continue;
        }

        $statusPath = $cacheDir . '/portfolio-' . $key . '.status.json';
        $metadataPath = $cacheDir . '/portfolio-' . $key . '.meta.json';
        $status = is_file($statusPath) ? json_decode((string) @file_get_contents($statusPath), true) : null;
        $result = is_file($resultPath) ? json_decode((string) @file_get_contents($resultPath), true) : null;
        if (!is_array($result) || ($result['status'] ?? '') !== 'ready'
            || (is_array($status) && ($status['status'] ?? '') !== 'ready')) {
            flock($lock, LOCK_UN);
            fclose($lock);
            continue;
        }

        $metadata = is_file($metadataPath) ? json_decode((string) @file_get_contents($metadataPath), true) : null;
        $rows = is_array($metadata['ticker_set']['symbols'] ?? null)
            ? $metadata['ticker_set']['symbols']
            : (is_array($result['ticker_leaders'] ?? null) ? $result['ticker_leaders'] : []);
        $symbols = [];
        foreach ($rows as $row) {
            $symbol = is_array($row) && array_key_exists('symbol', $row) ? (string) $row['symbol'] : (is_string($row) ? $row : '');
            if ($symbol !== '') $symbols[] = $symbol;
        }
        sort($symbols, SORT_STRING);
        if ($symbols !== $expectedSymbols) {
            flock($lock, LOCK_UN);
            fclose($lock);
            continue;
        }

        // Keep the lock file as a stable synchronization inode for concurrent ranking requests.
        $itemFailed = false;
        foreach ([$resultPath, $statusPath, $metadataPath, $cacheDir . '/portfolio-' . $key . '.log'] as $path) {
            if (is_file($path) && !@unlink($path)) $itemFailed = true;
        }
        if (!is_file($resultPath)) $deleted++;
        if ($itemFailed) $failed = true;
        flock($lock, LOCK_UN);
        fclose($lock);
    }

    if ($failed) respond(['status' => 'error', 'deleted' => $deleted, 'message' => 'Часть сохранённых рейтингов не удалось удалить.'], 500);
    respond(['status' => 'ready', 'deleted' => $deleted]);
} catch (Throwable $error) {
    respond(['status' => 'error', 'message' => 'Не удалось удалить сохранённые общие рейтинги.'], 500);
}
