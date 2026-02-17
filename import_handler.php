<?php
// Простейший обработчик для тестирования приёма данных
file_put_contents(__DIR__ . '/import_log.txt',
    date('Y-m-d H:i:s') . "\n" .
    print_r(json_decode(file_get_contents('php://input'), true), true) .
    "\n--------------------\n",
    FILE_APPEND
);
http_response_code(200);
echo 'OK';