<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/market-cap-lib.php';

$root = dirname(__DIR__);
$config = json_decode((string) file_get_contents($root . '/config.json'), true, 512, JSON_THROW_ON_ERROR);
$refreshPath = marketCapCachePath() . '.refreshing';

try {
    refreshMarketCapCache($config);
} catch (Throwable $error) {
    error_log('Market-cap refresh failed: ' . $error->getMessage());
} finally {
    @unlink($refreshPath);
}
