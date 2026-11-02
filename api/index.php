<?php
// Usage: index.php?action=search&term=...&entity=song|album|musicArtist
$cfg = require __DIR__ . '/config.php';
$cfg += [
  'app_origin' => getenv('APP_ORIGIN') ?: 'https://mman.ir',
  'bale_token' => getenv('BALE_TOKEN') ?: '',
  'bale_file_secret' => getenv('BALE_FILE_SECRET') ?: '',
  'bale_channel_username' => getenv('BALE_CHANNEL_USERNAME') ?: '',
  'telegram_channel_username' => getenv('TELEGRAM_CHANNEL_USERNAME') ?: '',
];
header('Content-Type: application/json; charset=utf-8');
if (($_SERVER['HTTP_ORIGIN'] ?? '') === $cfg['app_origin']) {
  header('Access-Control-Allow-Origin: ' . $cfg['app_origin']);
  header('Access-Control-Allow-Credentials: true');
  header('Vary: Origin');
}
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
  $pdo->exec('PRAGMA foreign_keys = ON');
  $pdo->exec('PRAGMA busy_timeout = 5000');
  $schema = file_get_contents(__DIR__ . '/schema.sql');
  if ($schema === false) throw new RuntimeException('Unable to read SQLite schema');
  $pdo->exec($schema);
  $queueColumns = $pdo->query('PRAGMA table_info(queue)')->fetchAll(PDO::FETCH_COLUMN, 1);
  if (!in_array('user_id', $queueColumns, true)) {
    $pdo->exec('DROP TRIGGER IF EXISTS queue_updated_at');
    $pdo->exec('BEGIN IMMEDIATE TRANSACTION');
    try {
      $pdo->exec('ALTER TABLE queue RENAME TO queue_legacy');
      $pdo->exec("CREATE TABLE queue (
        id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER REFERENCES users(id) ON DELETE CASCADE,
        track_id INTEGER NOT NULL,
        status TEXT NOT NULL DEFAULT 'queued' CHECK (status IN ('queued','searching','downloading','lyrics','tagging','uploading_bale','uploading_telegram','done','failed')),
        progress INTEGER NOT NULL DEFAULT 0 CHECK (progress BETWEEN 0 AND 100), message TEXT NOT NULL DEFAULT '',
        attempts INTEGER NOT NULL DEFAULT 0, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE(user_id, track_id)
      )");
      $pdo->exec('INSERT INTO queue (id, track_id, status, progress, message, attempts, created_at, updated_at)
        SELECT id, track_id, status, progress, message, attempts, created_at, updated_at FROM queue_legacy');
      $pdo->exec('DROP TABLE queue_legacy');
      $pdo->exec('COMMIT');
    } catch (Throwable $e) {
      $pdo->exec('ROLLBACK');
      throw $e;
    }
    $pdo->exec('CREATE INDEX IF NOT EXISTS queue_status_id ON queue(status, id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS queue_user_status_id ON queue(user_id, status, id)');
  }
  $columns = $pdo->query('PRAGMA table_info(track_files)')->fetchAll(PDO::FETCH_COLUMN, 1);
  if (!in_array('telegram_file_id', $columns, true)) {
    $pdo->exec('ALTER TABLE track_files ADD COLUMN telegram_file_id TEXT');
  }
  if (!in_array('bale_file_id', $columns, true)) {
    $pdo->exec('ALTER TABLE track_files ADD COLUMN bale_file_id TEXT');
  }
  $pdo->exec('CREATE INDEX IF NOT EXISTS queue_user_status_id ON queue(user_id, status, id)');
  $pdo->exec("CREATE TRIGGER IF NOT EXISTS queue_updated_at AFTER UPDATE ON queue
    WHEN NEW.updated_at = OLD.updated_at
    BEGIN UPDATE queue SET updated_at = CURRENT_TIMESTAMP WHERE id = OLD.id; END");
} catch (Throwable $e) {
  error_log('mman database initialization failed: ' . $e->getMessage());
  out(['error' => 'database_initialization_failed'], 500);
}

function body() { return json_decode(file_get_contents('php://input'), true) ?: $_POST; }
function current_user($required = false) {
  global $pdo;
  $token = $_COOKIE['mman_session'] ?? '';
  if (!is_string($token) || strlen($token) !== 64 || !ctype_xdigit($token)) {
    if ($required) out(['error' => 'authentication_required'], 401);
    return null;
  }
  $q = $pdo->prepare("SELECT u.id, u.email FROM sessions s JOIN users u ON u.id=s.user_id
    WHERE s.token_hash=? AND s.expires_at > datetime('now')");
  $q->execute([hash('sha256', $token)]);
  $user = $q->fetch() ?: null;
  if (!$user && $required) out(['error' => 'authentication_required'], 401);
  return $user;
}
function set_session_cookie($token, $expires) {
  setcookie('mman_session', $token, [
    'expires' => $expires, 'path' => '/', 'secure' => true,
    'httponly' => true, 'samesite' => 'Strict',
  ]);
}
function signed_bale_url($trackId) {
  global $cfg;
  if ($cfg['bale_file_secret'] === '') return null;
  $expires = time() + 86400;
  $signature = hash_hmac('sha256', $trackId . ':' . $expires, $cfg['bale_file_secret']);
  return rtrim($cfg['app_origin'], '/') . '/api/proxy.php?' . http_build_query([
    'track_id' => $trackId, 'expires' => $expires, 'signature' => $signature,
  ]);
}
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
    $collectionIds = array_values(array_filter(array_map(
      fn($album) => ($album['wrapperType'] ?? '') === 'collection' ? (int)$album['collectionId'] : null,
      $albums
    )));
    foreach (array_chunk($collectionIds, 10) as $batch) {
      $res = array_merge($res, itunes('lookup', ['id' => implode(',', $batch), 'entity' => 'song', 'limit' => 200]));
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
  case 'register': {
    $b = body(); $emailInput = $b['email'] ?? ''; $password = $b['password'] ?? '';
    if (!is_string($emailInput) || !is_string($password)) out(['error' => 'email_and_password_required'], 400);
    $email = strtolower(trim($emailInput));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254 || strlen($password) < 10) {
      out(['error' => 'email_and_password_required', 'message' => 'Use a valid email and a password with at least 10 characters.'], 400);
    }
    try {
      $pdo->prepare('INSERT INTO users (email, password_hash) VALUES (?, ?)')->execute([$email, password_hash($password, PASSWORD_DEFAULT)]);
    } catch (PDOException $e) {
      if (str_contains(strtolower($e->getMessage()), 'unique')) out(['error' => 'email_already_registered'], 409);
      throw $e;
    }
    $userId = (int)$pdo->lastInsertId(); $token = bin2hex(random_bytes(32)); $expires = time() + 30 * 86400;
    $pdo->prepare("INSERT INTO sessions (token_hash, user_id, expires_at) VALUES (?, ?, datetime(?, 'unixepoch'))")
      ->execute([hash('sha256', $token), $userId, $expires]);
    set_session_cookie($token, $expires);
    out(['user' => ['id' => $userId, 'email' => $email]]);
  }
  case 'login': {
    $b = body(); $emailInput = $b['email'] ?? ''; $password = $b['password'] ?? '';
    if (!is_string($emailInput) || !is_string($password)) out(['error' => 'invalid_credentials'], 401);
    $email = strtolower(trim($emailInput));
    if ($email === '' || $password === '') out(['error' => 'invalid_credentials'], 401);
    $q = $pdo->prepare('SELECT id, email, password_hash FROM users WHERE email=?'); $q->execute([$email]); $user = $q->fetch();
    if (!$user || !password_verify($password, $user['password_hash'])) out(['error' => 'invalid_credentials'], 401);
    $token = bin2hex(random_bytes(32)); $expires = time() + 30 * 86400;
    $pdo->prepare("INSERT INTO sessions (token_hash, user_id, expires_at) VALUES (?, ?, datetime(?, 'unixepoch'))")
      ->execute([hash('sha256', $token), $user['id'], $expires]);
    set_session_cookie($token, $expires);
    out(['user' => ['id' => (int)$user['id'], 'email' => $user['email']]]);
  }
  case 'logout': {
    $token = $_COOKIE['mman_session'] ?? '';
    if (is_string($token) && strlen($token) === 64 && ctype_xdigit($token)) {
      $pdo->prepare('DELETE FROM sessions WHERE token_hash=?')->execute([hash('sha256', $token)]);
    }
    setcookie('mman_session', '', ['expires' => time() - 3600, 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Strict']);
    out(['ok' => true]);
  }
  case 'me': {
    $user = current_user();
    out(['user' => $user ? ['id' => (int)$user['id'], 'email' => $user['email']] : null]);
  }
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
    $user = current_user(true);
    $b = body(); $type = $b['type'] ?? ''; $id = (int)($b['id'] ?? 0);
    if (!in_array($type, ['track', 'collection', 'artist']) || !$id) out(['error' => 'bad request'], 400);
    $ids = tracks_for($pdo, $type, $id);
    $st = $pdo->prepare("INSERT INTO queue (user_id, track_id) VALUES (?, ?) ON CONFLICT(user_id, track_id) DO UPDATE SET
      progress=CASE WHEN status='failed' THEN 0 ELSE progress END,
      message=CASE WHEN status='failed' THEN '' ELSE message END,
      status=CASE WHEN status='failed' THEN 'queued' ELSE status END");
    $pdo->beginTransaction();
    try {
      foreach ($ids as $t) $st->execute([$user['id'], $t]);
      $pdo->commit();
    } catch (Throwable $e) {
      $pdo->rollBack();
      throw $e;
    }
    out(['added' => count($ids), 'track_ids' => $ids]);
  }
  case 'queue_list': {
    $user = current_user(true);
    $page = max(1, (int)($_GET['page'] ?? 1)); $pp = min(100, max(1, (int)($_GET['per_page'] ?? 20)));
    $status = $_GET['status'] ?? ''; $w = 'WHERE q.user_id=?'; $p = [(int)$user['id']];
    if ($status !== '') { $w .= ' AND q.status=?'; $p[] = $status; }
    $c = $pdo->prepare("SELECT COUNT(*) FROM queue q $w"); $c->execute($p); $total = (int)$c->fetchColumn();
    $summary = $pdo->prepare('SELECT status, COUNT(*) AS count FROM queue WHERE user_id=? GROUP BY status');
    $summary->execute([(int)$user['id']]);
    $statusCounts = array_fill_keys(
      ['queued', 'searching', 'downloading', 'lyrics', 'tagging', 'uploading_bale', 'uploading_telegram', 'done', 'failed'],
      0
    );
    foreach ($summary->fetchAll() as $row) $statusCounts[$row['status']] = (int)$row['count'];
    $off = ($page - 1) * $pp;
    $s = $pdo->prepare("SELECT q.id,q.status,q.progress,q.message,q.updated_at,t.track_id,t.name,t.artist_name,t.collection_name,t.artwork,
      f.bale_message_id,f.bale_file_id,f.telegram_message_id,f.telegram_file_id FROM queue q JOIN tracks t ON t.track_id=q.track_id
      LEFT JOIN track_files f ON f.track_id=q.track_id $w ORDER BY q.id DESC LIMIT $pp OFFSET $off");
    $s->execute($p); $rows = $s->fetchAll();
    foreach ($rows as &$r) {
      $r['bale_stream_url'] = $r['bale_file_id'] ? signed_bale_url((int)$r['track_id']) : null;
      $r['bale_url'] = $r['bale_message_id'] && $cfg['bale_channel_username'] ? "https://ble.ir/{$cfg['bale_channel_username']}/{$r['bale_message_id']}" : null;
      $r['telegram_url'] = $r['telegram_message_id'] && $cfg['telegram_channel_username'] ? "https://t.me/{$cfg['telegram_channel_username']}/{$r['telegram_message_id']}" : null;
    }
    out([
      'page' => $page,
      'per_page' => $pp,
      'total' => $total,
      'pages' => (int)ceil($total / $pp),
      'status_counts' => $statusCounts,
      'items' => $rows,
    ]);
  }
  case 'queue_remove': {
    $user = current_user(true); $b = body(); $queueId = (int)($b['queue_id'] ?? 0);
    if ($queueId < 1) out(['error' => 'queue_id_required'], 400);
    $s = $pdo->prepare("DELETE FROM queue WHERE id=? AND user_id=? AND status IN ('queued','failed','done')");
    $s->execute([$queueId, $user['id']]);
    if ($s->rowCount() < 1) out(['error' => 'queue_item_not_removable'], 409);
    out(['ok' => true]);
  }
  case 'favorite': {
    $user = current_user(true); $b = body(); $trackId = (int)($b['track_id'] ?? 0);
    if ($trackId < 1) out(['error' => 'track_id_required'], 400);
    $exists = $pdo->prepare('SELECT 1 FROM tracks WHERE track_id=?'); $exists->execute([$trackId]);
    if (!$exists->fetchColumn()) out(['error' => 'unknown_track'], 404);
    $q = $pdo->prepare('SELECT 1 FROM favorites WHERE user_id=? AND track_id=?');
    $q->execute([$user['id'], $trackId]);
    if ($q->fetchColumn()) {
      $pdo->prepare('DELETE FROM favorites WHERE user_id=? AND track_id=?')->execute([$user['id'], $trackId]);
      out(['favorite' => false]);
    }
    $pdo->prepare('INSERT INTO favorites (user_id, track_id) VALUES (?, ?)')->execute([$user['id'], $trackId]);
    out(['favorite' => true]);
  }
  case 'favorites': {
    $user = current_user(true); $page = max(1, (int)($_GET['page'] ?? 1)); $pp = min(100, max(1, (int)($_GET['per_page'] ?? 20)));
    $count = $pdo->prepare('SELECT COUNT(*) FROM favorites WHERE user_id=?'); $count->execute([$user['id']]); $total = (int)$count->fetchColumn();
    $offset = ($page - 1) * $pp;
    $s = $pdo->prepare('SELECT t.track_id,t.name,t.artist_name,t.collection_name,t.artwork,t.duration_ms,
      tf.bale_file_id,tf.telegram_file_id FROM favorites f JOIN tracks t ON t.track_id=f.track_id
      LEFT JOIN track_files tf ON tf.track_id=t.track_id WHERE f.user_id=? ORDER BY f.created_at DESC LIMIT ? OFFSET ?');
    $s->bindValue(1, $user['id'], PDO::PARAM_INT); $s->bindValue(2, $pp, PDO::PARAM_INT); $s->bindValue(3, $offset, PDO::PARAM_INT); $s->execute();
    $items = $s->fetchAll();
    foreach ($items as &$item) $item['bale_stream_url'] = $item['bale_file_id'] ? signed_bale_url((int)$item['track_id']) : null;
    out(['page' => $page, 'per_page' => $pp, 'total' => $total, 'pages' => (int)ceil($total / $pp), 'items' => $items]);
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
  case 'save_files': { // POST partial file and message metadata
    need_key(); $b = body(); $allowed = ['track_id', 'youtube_id', 'file_name', 'file_size', 'bale_message_id', 'bale_file_id', 'telegram_message_id', 'telegram_file_id', 'lyrics_synced', 'lyrics_plain', 'lyrics_source'];
    $row = array_intersect_key($b, array_flip($allowed));
    if (empty($row['track_id'])) out(['error' => 'track_id required'], 400);
    upsert($pdo, 'track_files', $row); out(['ok' => true]);
  }
  default: out(['error' => 'unknown action'], 404);
}
