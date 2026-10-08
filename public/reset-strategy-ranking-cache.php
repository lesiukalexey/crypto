<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function respond(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    respond(['status' => 'error', 'message' => 'Для сброса кэша требуется POST-запрос.'], 405);
}

$origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
$host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
if ($origin !== '' && $host !== '' && strtolower((string) parse_url($origin, PHP_URL_HOST)) !== preg_replace('/:\d+$/', '', $host)) {
    respond(['status' => 'error', 'message' => 'Запрос на сброс кэша отклонен.'], 403);
}

try {
    $home = getenv('HOME') ?: '/home/alex';
    $cacheDir = $home . '/.ai/home/.local/bitget-backtest-cache';
    if (!is_dir($cacheDir) && !mkdir($cacheDir, 0700, true) && !is_dir($cacheDir)) {
        respond(['status' => 'error', 'message' => 'Не удалось подготовить кэш рейтинга.'], 500);
    }

    $generationPath = $cacheDir . '/strategy-ranking-generation.txt';
    $temporaryPath = tempnam($cacheDir, '.strategy-ranking-generation-');
    if ($temporaryPath === false) {
        respond(['status' => 'error', 'message' => 'Не удалось сбросить кэш рейтингов.'], 500);
    }
    $generation = bin2hex(random_bytes(16));
    if (file_put_contents($temporaryPath, $generation, LOCK_EX) === false || !rename($temporaryPath, $generationPath)) {
        @unlink($temporaryPath);
        respond(['status' => 'error', 'message' => 'Не удалось сбросить кэш рейтингов.'], 500);
    }

    respond(['status' => 'ready']);
} catch (Throwable $error) {
    respond(['status' => 'error', 'message' => 'Не удалось сбросить кэш рейтингов.'], 500);
}
