<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require_once __DIR__ . '/ranking-snapshot.php';

function respondTodaySnapshot(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    respondTodaySnapshot(['status' => 'error', 'message' => 'Требуется POST-запрос.'], 405);
}
$origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
$host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
if ($origin !== '' && $host !== '' && strtolower((string) parse_url($origin, PHP_URL_HOST)) !== preg_replace('/:\\d+$/', '', $host)) {
    respondTodaySnapshot(['status' => 'error', 'message' => 'Запрос обновления отклонён.'], 403);
}

try {
    $config = json_decode((string) file_get_contents(dirname(__DIR__) . '/config.json'), true, 512, JSON_THROW_ON_ERROR);
    $timezone = new DateTimeZone('Europe/Kyiv');
    $startValue = (string) ($_POST['start_day'] ?? '');
    $endValue = (string) ($_POST['end_day'] ?? '');
    $startDay = DateTimeImmutable::createFromFormat('!Y-m-d', $startValue, $timezone);
    $endDay = DateTimeImmutable::createFromFormat('!Y-m-d', $endValue, $timezone);
    $startErrors = DateTimeImmutable::getLastErrors();
    $endErrors = DateTimeImmutable::getLastErrors();
    if ($startDay === false || $endDay === false
        || ($startErrors !== false && ($startErrors['warning_count'] || $startErrors['error_count']))
        || ($endErrors !== false && ($endErrors['warning_count'] || $endErrors['error_count']))
        || $startDay->format('Y-m-d') !== $startValue || $endDay->format('Y-m-d') !== $endValue
        || $endDay < $startDay) {
        respondTodaySnapshot(['status' => 'error', 'message' => 'Проверьте выбранный диапазон дат.'], 400);
    }
    $db = $config['mysql'];
    $password = getenv($db['password_env']) ?: '';
    if ($password === '') respondTodaySnapshot(['status' => 'error', 'message' => 'Не задан пароль подключения к MySQL.'], 503);
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $db['host'], $db['port'], $db['database']),
        $db['user'], $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
    $cacheDir = (getenv('HOME') ?: '/home/alex') . '/.ai/home/.local/bitget-backtest-cache';
    if (!is_dir($cacheDir) && !mkdir($cacheDir, 0700, true) && !is_dir($cacheDir)) {
        respondTodaySnapshot(['status' => 'error', 'message' => 'Не удалось подготовить кэш рейтинга.'], 500);
    }
    $generationLock = fopen($cacheDir . '/strategy-ranking-generation.lock', 'c');
    if ($generationLock === false || !flock($generationLock, LOCK_SH)) {
        if (is_resource($generationLock)) fclose($generationLock);
        respondTodaySnapshot(['status' => 'error', 'message' => 'Не удалось проверить поколение кэша рейтинга.'], 503);
    }
    $generationPath = $cacheDir . '/strategy-ranking-generation.txt';
    $generation = is_file($generationPath) ? trim((string) file_get_contents($generationPath)) : 'initial';
    if ($generation === '') respondTodaySnapshot(['status' => 'error', 'message' => 'Не удалось прочитать поколение кэша рейтинга.'], 503);
    $startUtc = $startDay->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.v');
    $endUtc = $endDay->modify('+1 day')->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.v');
    $snapshotIds = rankingSnapshotIds($pdo, $cacheDir, $config['category'], $startValue, $endValue, $startUtc, $endUtc, $generation, true);
    respondTodaySnapshot(['status' => 'ready', 'symbols' => count($snapshotIds), 'revision' => rankingSnapshotRevision($cacheDir, $config['category'], $startValue, $endValue, $generation)]);
} catch (Throwable $error) {
    respondTodaySnapshot(['status' => 'error', 'message' => 'Не удалось обновить сегодняшний срез котировок.'], 503);
}
