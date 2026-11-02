<?php
declare(strict_types=1);

const MAX_UPDATE_BYTES = 20000;

function respond(int $status = 200): never {
  http_response_code($status);
  header('Content-Type: application/json; charset=utf-8');
  echo '{"ok":true}';
  exit;
}

function api_request(string $action, array $params = [], ?array $payload = null): array {
  $url = trim(getenv('API_URL') ?: 'https://mman.ir/api/index.php');
  $key = trim(getenv('API_KEY') ?: '');
  if ($url === '' || $key === '') throw new RuntimeException('API configuration is missing.');
  $separator = str_contains($url, '?') ? '&' : '?';
  $url .= $separator . http_build_query(['action' => $action] + $params);
  $headers = ['X-API-Key: ' . $key, 'Accept: application/json'];
  $options = [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 8,
    CURLOPT_TIMEOUT => 25,
    CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_HTTPHEADER => $headers,
  ];
  if ($payload !== null) {
    $options[CURLOPT_POST] = true;
    $options[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_THROW_ON_ERROR);
    $options[CURLOPT_HTTPHEADER][] = 'Content-Type: application/json';
  }
  $curl = curl_init($url);
  curl_setopt_array($curl, $options);
  $body = curl_exec($curl);
  $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
  $error = curl_error($curl);
  curl_close($curl);
  $data = is_string($body) ? json_decode($body, true) : null;
  if ($status < 200 || $status >= 300 || !is_array($data)) {
    throw new RuntimeException('API request failed (' . $status . '): ' . $error);
  }
  return $data;
}

function send_message(string $platform, string $chatId, string $text): void {
  $token = trim(getenv($platform === 'telegram' ? 'TG_BOT_TOKEN' : 'BALE_TOKEN') ?: '');
  if ($token === '') throw new RuntimeException('Bot token is missing.');
  $host = $platform === 'telegram' ? 'api.telegram.org' : 'tapi.bale.ai';
  $url = 'https://' . $host . '/bot' . rawurlencode($token) . '/sendMessage';
  $curl = curl_init($url);
  curl_setopt_array($curl, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query(['chat_id' => $chatId, 'text' => $text]),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 8,
    CURLOPT_TIMEOUT => 20,
    CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
    CURLOPT_FOLLOWLOCATION => false,
  ]);
  $body = curl_exec($curl);
  $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
  $error = curl_error($curl);
  curl_close($curl);
  $data = is_string($body) ? json_decode($body, true) : null;
  if ($status < 200 || $status >= 300 || empty($data['ok'])) {
    throw new RuntimeException('Bot message delivery failed (' . $status . '): ' . $error);
  }
}

function clean_text(string $text, int $max = 200): string {
  $text = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $text) ?? '';
  return mb_substr(trim($text), 0, $max);
}

$platform = $_GET['platform'] ?? '';
if (!in_array($platform, ['telegram', 'bale'], true)) respond(404);
$secretName = $platform === 'telegram' ? 'TELEGRAM_WEBHOOK_SECRET' : 'BALE_WEBHOOK_SECRET';
$expectedSecret = trim(getenv($secretName) ?: '');
$providedSecret = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ??
  $_SERVER['HTTP_X_BOT_API_SECRET_TOKEN'] ?? '';
if ($expectedSecret === '' || !is_string($providedSecret) || !hash_equals($expectedSecret, $providedSecret)) respond(403);
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') respond(405);

$raw = file_get_contents('php://input', false, null, 0, MAX_UPDATE_BYTES + 1);
if (!is_string($raw) || strlen($raw) > MAX_UPDATE_BYTES) respond(413);
$update = json_decode($raw, true);
$message = is_array($update) ? ($update['message'] ?? $update['edited_message'] ?? null) : null;
$chatId = $message['chat']['id'] ?? null;
$text = $message['text'] ?? '';
if (!is_array($message) || !is_scalar($chatId) || !is_string($text)) respond();
$text = trim($text);
if (!preg_match('/^\/(start|help|search|crawl|album|artist)(?:@[A-Za-z0-9_]+)?(?:\s+(.+))?$/us', $text, $match)) respond();
$command = strtolower($match[1]);
$argument = trim($match[2] ?? '');
$reply = 'Commands: /search song or artist, /crawl TRACK_ID, /album ALBUM_ID, /artist ARTIST_ID. Each crawl request queues up to 300 tracks.';

try {
  if ($command === 'search' && $argument !== '') {
    $data = api_request('search', ['term' => clean_text($argument, 120), 'entity' => 'song', 'limit' => 5]);
    $items = array_values(array_filter($data['results'] ?? [], static fn($item) => ($item['wrapperType'] ?? '') === 'track'));
    if (!$items) {
      $reply = 'No tracks found. Try a shorter search.';
    } else {
      $lines = ['Top matches (use /crawl TRACK_ID):'];
      foreach ($items as $item) {
        $lines[] = clean_text((string)($item['trackName'] ?? 'Track')) . ' — ' .
          clean_text((string)($item['artistName'] ?? 'Unknown artist')) . ' [' . (int)($item['trackId'] ?? 0) . ']';
      }
      $reply = implode("\n", $lines);
    }
  } elseif (in_array($command, ['crawl', 'album', 'artist'], true)) {
    $type = $command === 'crawl' ? 'track' : ($command === 'album' ? 'collection' : 'artist');
    if ($command === 'crawl' && preg_match('/^(artist|album|track)\s+(\d+)$/i', $argument, $parts)) {
      $type = strtolower($parts[1]) === 'album' ? 'collection' : strtolower($parts[1]);
      $argument = $parts[2];
    }
    if (!ctype_digit($argument) || (int)$argument < 1) {
      $reply = 'Use a numeric catalog ID, for example /crawl 123456789.';
    } else {
      $data = api_request('bot_queue_add', [], ['type' => $type, 'id' => (int)$argument]);
      $reply = (int)($data['added'] ?? 0) . ' track(s) added to the shared crawl queue. The worker publishes completed tracks to configured Telegram and Bale channels.';
    }
  }
  send_message($platform, (string)$chatId, $reply);
} catch (Throwable $error) {
  error_log('mman ' . $platform . ' webhook failed: ' . $error->getMessage());
  try { send_message($platform, (string)$chatId, 'The request could not be completed. Please try again later.'); }
  catch (Throwable $deliveryError) { error_log('mman ' . $platform . ' reply failed: ' . $deliveryError->getMessage()); }
}

respond();