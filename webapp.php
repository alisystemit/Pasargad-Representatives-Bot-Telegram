<?php
declare(strict_types=1);

// سرو کردن فایل Mini App
$file = __DIR__ . '/assets/webapp.html';

if (is_file($file)) {
    header('Content-Type: text/html; charset=utf-8');
    readfile($file);
    exit;
}

http_response_code(404);
echo 'Not found';