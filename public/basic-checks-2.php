<?php
declare(strict_types=1);

http_response_code(410);
header('Content-Type: text/plain; charset=utf-8');
echo 'Раздел «Базовые проверки 2» удалён.';
