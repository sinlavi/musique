<?php
$cfg = require __DIR__ . '/config.php';
$cfg += [
  'bale_token' => getenv('BALE_TOKEN') ?: '',
  'bale_file_secret' => getenv('BALE_FILE_SECRET') ?: '',
];
if (!in_array($_SERVER['REQUEST_METHOD'] ?? '', ['GET', 'HEAD'], true)) {
  http_response_code(405);
  exit;
}

$trackId = filter_input(INPUT_GET, 'track_id', FILTER_VALIDATE_INT);
$expires = filter_input(INPUT_GET, 'expires', FILTER_VALIDATE_INT);
$signature = $_GET['signature'] ?? '';
if (!$trackId || !$expires || !is_string($signature) || $cfg['bale_file_secret'] === '' || $cfg['bale_token'] === '') {
  http_response_code(404);
  exit;
}
if ($expires < time() || $expires > time() + 86400) {
  http_response_code(403);
  exit;
}
$expected = hash_hmac('sha256', $trackId . ':' . $expires, $cfg['bale_file_secret']);
if (!hash_equals($expected, $signature)) {
  http_response_code(403);
  exit;
}

try {
  $pdo = new PDO('sqlite:' . $cfg['sqlite_path'], null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  ]);
  $q = $pdo->prepare('SELECT bale_file_id, file_name FROM track_files WHERE track_id=?');
  $q->execute([$trackId]);
  $file = $q->fetch();
} catch (Throwable $e) {
  error_log('Bale stream database error: ' . $e->getMessage());
  http_response_code(503);
  exit;
}
if (!$file || !$file['bale_file_id']) {
  http_response_code(404);
  exit;
}

$getFile = curl_init('https://tapi.bale.ai/bot' . rawurlencode($cfg['bale_token']) .
  '/getFile?file_id=' . rawurlencode($file['bale_file_id']));
curl_setopt_array($getFile, [
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_CONNECTTIMEOUT => 10,
  CURLOPT_TIMEOUT => 20,
  CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
  CURLOPT_FOLLOWLOCATION => false,
]);
$fileInfoJson = curl_exec($getFile);
$fileInfoStatus = curl_getinfo($getFile, CURLINFO_HTTP_CODE);
$fileInfoError = curl_error($getFile);
curl_close($getFile);
$fileInfo = is_string($fileInfoJson) ? json_decode($fileInfoJson, true) : null;
$filePath = $fileInfo['result']['file_path'] ?? '';
if ($fileInfoStatus !== 200 || empty($fileInfo['ok']) || !is_string($filePath) ||
    $filePath === '' || str_contains($filePath, '..') || str_starts_with($filePath, '/')) {
  error_log('Bale getFile failed: HTTP ' . $fileInfoStatus . ' ' . $fileInfoError);
  http_response_code(502);
  exit;
}

$encodedFilePath = implode('/', array_map('rawurlencode', explode('/', $filePath)));
$downloadUrl = 'https://tapi.bale.ai/file/bot' . rawurlencode($cfg['bale_token']) . '/' . $encodedFilePath;
$temp = fopen('php://temp/maxmemory:2097152', 'w+b');
if ($temp === false) {
  http_response_code(500);
  exit;
}
$headers = [];
$range = $_SERVER['HTTP_RANGE'] ?? '';
if ($range !== '' && !preg_match('/^bytes=(?:\d+-\d*|-\d+)$/', $range)) {
  fclose($temp);
  http_response_code(416);
  exit;
}
$download = curl_init($downloadUrl);
$options = [
  CURLOPT_FILE => $temp,
  CURLOPT_CONNECTTIMEOUT => 10,
  CURLOPT_TIMEOUT => 600,
  CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
  CURLOPT_FOLLOWLOCATION => false,
  CURLOPT_HEADERFUNCTION => function ($curl, $line) use (&$headers) {
    $length = strlen($line);
    if (str_contains($line, ':')) {
      [$name, $value] = explode(':', trim($line), 2);
      $headers[strtolower($name)] = trim($value);
    }
    return $length;
  },
];
if ($range !== '') $options[CURLOPT_RANGE] = substr($range, 6);
curl_setopt_array($download, $options);
$downloaded = curl_exec($download);
$status = curl_getinfo($download, CURLINFO_HTTP_CODE);
$downloadError = curl_error($download);
curl_close($download);
$size = ftell($temp);
if ($downloaded === false || !in_array($status, [200, 206], true) || $size === false) {
  fclose($temp);
  error_log('Bale file download failed: HTTP ' . $status . ' ' . $downloadError);
  http_response_code(502);
  exit;
}

http_response_code($status);
$contentType = $headers['content-type'] ?? 'audio/mpeg';
if (!preg_match('~^[A-Za-z0-9!#$&^_.+-]+/[A-Za-z0-9!#$&^_.+-]+$~', $contentType)) {
  $contentType = 'audio/mpeg';
}
header('Content-Type: ' . $contentType);
header('Content-Length: ' . $size);
header('Accept-Ranges: bytes');
header('Cache-Control: private, max-age=300');
header('X-Content-Type-Options: nosniff');
if (isset($headers['content-range']) &&
    preg_match('/^bytes \d+-\d+\/(?:\d+|\*)$/', $headers['content-range'])) {
  header('Content-Range: ' . $headers['content-range']);
}
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
  rewind($temp);
  fpassthru($temp);
}
fclose($temp);
