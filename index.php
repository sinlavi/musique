<?php
$index = __DIR__ . '/web/index.html';
if (!is_file($index)) {
  http_response_code(503);
  exit('mman is not installed correctly.');
}
header('Content-Type: text/html; charset=utf-8');
readfile($index);
