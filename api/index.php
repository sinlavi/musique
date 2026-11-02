<?php
// Usage: index.php?action=search&term=...&entity=song|album|musicArtist
$cfg = require __DIR__ . '/config.php';
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, X-API-Key');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit;
function out($d, $c = 200) { http_response_code($c); echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; }

try {
  $dbDir = dirname($cfg['sqlite_path']);
  if ($cfg['sqlite_path'] !== ':memory:' && !is_dir($dbDir) && !mkdir($dbDir, 0770, true) && !is_dir($dbDir)) {
    throw new RuntimeException('Unable to create SQLite database directory');
  }
  $pdo = new PDO('sqlite:' . $cfg['sqlite_path'], null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_TIMEOUT => 5,
  ]);
  $pdo->exec('PRAGMA busy_timeout = 5000');
  $schema = file_get_contents(__DIR__ . '/schema.sql');
  if ($schema === false) throw new RuntimeException('Unable to read SQLite schema');
  $pdo->exec($schema);
  $columns = $pdo->query('PRAGMA table_info(track_files)')->fetchAll(PDO::FETCH_COLUMN, 1);
  if (!in_array('telegram_file_id', $columns, true)) {
    $pdo->exec('ALTER TABLE track_files ADD COLUMN telegram_file_id TEXT');
  }
} catch (Throwable $e) {
  error_log('mman database initialization failed: ' . $e->getMessage());
  out(['error' => 'database_initialization_failed'], 500);
}

function body() { return json_decode(file_get_contents('php://input'), true) ?: $_POST; }
function need_key() {
  global $cfg; $k = $_SERVER['HTTP_X_API_KEY'] ?? '';
  if ($cfg['api_key'] === '' || !hash_equals($cfg['api_key'], $k)) out(['error' => 'unauthorized'], 401);
}
function http_get($url, $headers = []) {
  $ch = curl_init($url);
  curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => 1, CURLOPT_TIMEOUT => 20, CURLOPT_FOLLOWLOCATION => 1,
    CURLOPT_HTTPHEADER => array_merge(['User-Agent: mman-proxy/1.0'], $headers)]);
  $r = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
  return [$code, $r];
}
function itunes($path, $params) {
  global $cfg; $params += ['country' => $cfg['country']];
  [$code, $r] = http_get("https://itunes.apple.com/$path?" . http_build_query($params));
  if ($code !== 200) out(['error' => 'itunes_failed', 'code' => $code], 502);
  return json_decode($r, true)['results'] ?? [];
}
function upsert($pdo, $t, $row) {
  $c = array_keys($row);
  $primaryKeys = ['artists' => 'artist_id', 'collections' => 'collection_id', 'tracks' => 'track_id', 'track_files' => 'track_id'];
  $key = $primaryKeys[$t] ?? null;
  if ($key === null || !in_array($key, $c, true)) throw new InvalidArgumentException('Invalid upsert table or primary key');
  $updates = array_values(array_filter($c, fn($column) => $column !== $key));
  if (!$updates) throw new InvalidArgumentException('Upsert requires at least one non-key column');
  $sql = "INSERT INTO $t (" . implode(',', $c) . ") VALUES (" . implode(',', array_map(fn($x) => ":$x", $c)) .
    ") ON CONFLICT($key) DO UPDATE SET " . implode(',', array_map(fn($x) => "$x=excluded.$x", $updates));
  $pdo->prepare($sql)->execute($row);
}
function d($s) { return $s ? substr($s, 0, 10) : null; }
function art($u) { return $u ? str_replace('100x100bb', '600x600bb', $u) : null; }

// Save each wrapperType into its own table
function save_results($pdo, $results) {
  foreach ($results as $r) {
    $raw = json_encode($r, JSON_UNESCAPED_UNICODE);
    switch ($r['wrapperType'] ?? '') {
      case 'artist':
        upsert($pdo, 'artists', ['artist_id' => $r['artistId'], 'name' => $r['artistName'] ?? null,
          'genre' => $r['primaryGenreName'] ?? null, 'url' => $r['artistLinkUrl'] ?? null, 'raw' => $raw]);
        break;
      case 'collection':
        upsert($pdo, 'collections', ['collection_id' => $r['collectionId'], 'artist_id' => $r['artistId'] ?? null,
          'name' => $r['collectionName'] ?? null, 'artist_name' => $r['artistName'] ?? null,
          'artwork' => art($r['artworkUrl100'] ?? null), 'track_count' => $r['trackCount'] ?? null,
          'release_date' => d($r['releaseDate'] ?? null), 'genre' => $r['primaryGenreName'] ?? null,
          'explicit' => $r['collectionExplicitness'] ?? null, 'copyright' => $r['copyright'] ?? null, 'raw' => $raw]);
        break;
      case 'track':
        upsert($pdo, 'tracks', ['track_id' => $r['trackId'], 'collection_id' => $r['collectionId'] ?? null,
          'artist_id' => $r['artistId'] ?? null, 'name' => $r['trackName'] ?? null,
          'artist_name' => $r['artistName'] ?? null, 'collection_name' => $r['collectionName'] ?? null,
          'artwork' => art($r['artworkUrl100'] ?? null), 'duration_ms' => $r['trackTimeMillis'] ?? null,
          'track_number' => $r['trackNumber'] ?? null, 'track_count' => $r['trackCount'] ?? null,
          'disc_number' => $r['discNumber'] ?? null, 'disc_count' => $r['discCount'] ?? null,
          'release_date' => d($r['releaseDate'] ?? null), 'genre' => $r['primaryGenreName'] ?? null,
          'explicit' => $r['trackExplicitness'] ?? null, 'preview_url' => $r['previewUrl'] ?? null, 'raw' => $raw]);
        break;
    }
  }
}
function slim($results) { // upscale artwork for the client
  return array_map(function ($r) { if (isset($r['artworkUrl100'])) $r['artwork'] = art($r['artworkUrl100']); return $r; }, $results);
}
// Resolve track ids for a track / collection / artist (artist = all songs of all albums)
function tracks_for($pdo, $type, $id) {
  if ($type === 'track' || $type === 'collection') $res = itunes('lookup', ['id' => $id, 'entity' => 'song']);
  else {
    $res = []; $albums = itunes('lookup', ['id' => $id, 'entity' => 'album', 'limit' => 200]);
    save_results($pdo, $albums);
    foreach ($albums as $a) {
      if (($a['wrapperType'] ?? '') !== 'collection') continue;
      $res = array_merge($res, itunes('lookup', ['id' => $a['collectionId'], 'entity' => 'song']));
    }
  }
  save_results($pdo, $res); $tracks = [];
  foreach ($res as $r) if (($r['wrapperType'] ?? '') === 'track' && ($r['kind'] ?? '') === 'song') $tracks[$r['trackId']] = 1;
  return array_keys($tracks);
}
function lyrics_row($pdo, $tid) {
  $s = $pdo->prepare('SELECT lyrics_synced, lyrics_plain, lyrics_source FROM track_files WHERE track_id=?');
  $s->execute([$tid]); return $s->fetch();
}

$action = $_GET['action'] ?? '';
switch ($action) {
  case 'search': {
    $term = trim($_GET['term'] ?? ''); if ($term === '') out(['error' => 'term required'], 400);
    $entity = in_array($_GET['entity'] ?? '', ['song', 'album', 'musicArtist']) ? $_GET['entity'] : 'song';
    $res = itunes('search', ['term' => $term, 'media' => 'music', 'entity' => $entity, 'limit' => min(50, (int)($_GET['limit'] ?? 25))]);
    save_results($pdo, $res); out(['results' => slim($res)]);
  }
  case 'lookup': { // id + entity=song|album ; album id -> its songs, artist id -> its albums
    $res = itunes('lookup', ['id' => (int)$_GET['id'], 'entity' => $_GET['entity'] ?? 'song', 'limit' => 200]);
    save_results($pdo, $res); out(['results' => slim($res)]);
  }
  case 'queue_add': { // POST {type: track|collection|artist, id}
    $b = body(); $type = $b['type'] ?? ''; $id = (int)($b['id'] ?? 0);
    if (!in_array($type, ['track', 'collection', 'artist']) || !$id) out(['error' => 'bad request'], 400);
    $ids = tracks_for($pdo, $type, $id);
    $st = $pdo->prepare("INSERT INTO queue (track_id) VALUES (?) ON CONFLICT(track_id) DO UPDATE SET
      progress=CASE WHEN status='failed' THEN 0 ELSE progress END,
      message=CASE WHEN status='failed' THEN '' ELSE message END,
      status=CASE WHEN status='failed' THEN 'queued' ELSE status END");
    foreach ($ids as $t) $st->execute([$t]);
    out(['added' => count($ids), 'track_ids' => $ids]);
  }
  case 'queue_list': {
    $page = max(1, (int)($_GET['page'] ?? 1)); $pp = min(100, max(1, (int)($_GET['per_page'] ?? 20)));
    $status = $_GET['status'] ?? ''; $w = ''; $p = [];
    if ($status !== '') { $w = 'WHERE q.status=?'; $p[] = $status; }
    $c = $pdo->prepare("SELECT COUNT(*) FROM queue q $w"); $c->execute($p); $total = (int)$c->fetchColumn();
    $off = ($page - 1) * $pp;
    $s = $pdo->prepare("SELECT q.id,q.status,q.progress,q.message,q.updated_at,t.track_id,t.name,t.artist_name,t.collection_name,t.artwork,
      f.bale_message_id,f.telegram_message_id,f.telegram_file_id FROM queue q JOIN tracks t ON t.track_id=q.track_id
      LEFT JOIN track_files f ON f.track_id=q.track_id $w ORDER BY q.id DESC LIMIT $pp OFFSET $off");
    $s->execute($p); $rows = $s->fetchAll();
    foreach ($rows as &$r) {
      $r['bale_url'] = $r['bale_message_id'] && $cfg['bale_channel_username'] ? "https://ble.ir/{$cfg['bale_channel_username']}/{$r['bale_message_id']}" : null;
      $r['telegram_url'] = $r['telegram_message_id'] && $cfg['telegram_channel_username'] ? "https://t.me/{$cfg['telegram_channel_username']}/{$r['telegram_message_id']}" : null;
    }
    out(['page' => $page, 'per_page' => $pp, 'total' => $total, 'pages' => (int)ceil($total / $pp), 'items' => $rows]);
  }
  case 'lyrics': { // cached lyrics, else live lrclib fallback
    $tid = (int)($_GET['track_id'] ?? 0); $row = lyrics_row($pdo, $tid);
    if ($row && ($row['lyrics_synced'] || $row['lyrics_plain'])) out($row);
    $s = $pdo->prepare('SELECT * FROM tracks WHERE track_id=?'); $s->execute([$tid]); $t = $s->fetch();
    if (!$t) out(['error' => 'unknown track'], 404);
    [$code, $r] = http_get('https://lrclib.net/api/get?' . http_build_query(['track_name' => $t['name'], 'artist_name' => $t['artist_name'],
      'album_name' => $t['collection_name'], 'duration' => round($t['duration_ms'] / 1000)]));
    $j = $code === 200 ? json_decode($r, true) : null;
    if (!$j || (empty($j['syncedLyrics']) && empty($j['plainLyrics']))) out(['lyrics_synced' => null, 'lyrics_plain' => null, 'lyrics_source' => null]);
    $row = ['track_id' => $tid, 'lyrics_synced' => $j['syncedLyrics'], 'lyrics_plain' => $j['plainLyrics'], 'lyrics_source' => 'lrclib'];
    upsert($pdo, 'track_files', $row); unset($row['track_id']); out($row);
  }
  // ---------- bot endpoints (X-API-Key) ----------
  case 'queue_next': {
    need_key();
    $pdo->exec('BEGIN IMMEDIATE TRANSACTION');
    try {
      $pdo->exec("UPDATE queue SET status='queued' WHERE status NOT IN ('queued','done','failed') AND datetime(updated_at) < datetime('now','-30 minutes')");
      $q = $pdo->query("SELECT * FROM queue WHERE status='queued' ORDER BY id LIMIT 1")->fetch();
      if (!$q) { $pdo->exec('COMMIT'); out(['job' => null]); }
      $pdo->prepare("UPDATE queue SET status='searching', progress=5, message='Picked up by bot', attempts=attempts+1 WHERE id=?")->execute([$q['id']]);
      $pdo->exec('COMMIT');
    } catch (Throwable $e) {
      $pdo->exec('ROLLBACK');
      throw $e;
    }
    $s = $pdo->prepare('SELECT * FROM tracks WHERE track_id=?'); $s->execute([$q['track_id']]);
    $t = $s->fetch(); unset($t['raw']); out(['job' => ['queue_id' => $q['id'], 'track' => $t]]);
  }
  case 'queue_progress': { // POST {id, status, progress, message}
    need_key(); $b = body();
    $statuses = ['queued', 'searching', 'downloading', 'lyrics', 'tagging', 'uploading_bale', 'uploading_telegram', 'done', 'failed'];
    if ((int)($b['id'] ?? 0) < 1 || !in_array($b['status'] ?? '', $statuses, true)) out(['error' => 'bad request'], 400);
    $pdo->prepare('UPDATE queue SET status=?, progress=?, message=? WHERE id=?')
      ->execute([$b['status'], max(0, min(100, (int)($b['progress'] ?? 0))), mb_substr($b['message'] ?? '', 0, 500), (int)$b['id']]);
    out(['ok' => true]);
  }
  case 'save_files': { // POST partial {track_id, youtube_id, file_name, file_size, bale_message_id, telegram_message_id, telegram_file_id, lyrics_*}
    need_key(); $b = body(); $allowed = ['track_id', 'youtube_id', 'file_name', 'file_size', 'bale_message_id', 'telegram_message_id', 'telegram_file_id', 'lyrics_synced', 'lyrics_plain', 'lyrics_source'];
    $row = array_intersect_key($b, array_flip($allowed));
    if (empty($row['track_id'])) out(['error' => 'track_id required'], 400);
    upsert($pdo, 'track_files', $row); out(['ok' => true]);
  }
  default: out(['error' => 'unknown action'], 404);
}
