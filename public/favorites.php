<?php
declare(strict_types=1);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    http_response_code(410);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status' => 'removed', 'error' => 'Раздел избранного удалён.'], JSON_UNESCAPED_UNICODE);
    exit;
}
header('Location: index.php', true, 302);
exit;
