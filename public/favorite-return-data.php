<?php
declare(strict_types=1);
http_response_code(410);
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['status' => 'missing'], JSON_UNESCAPED_UNICODE);
