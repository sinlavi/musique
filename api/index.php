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
function attach_bale_playback($pdo, $results) {
  $trackIds = array_values(array_unique(array_filter(array_map(
    fn($row) => ($row['wrapperType'] ?? '') === 'track' ? (int)($row['trackId'] ?? 0) : 0,
    $results
  ))));
  if (!$trackIds) return $results;
  $placeholders = implode(',', array_fill(0, count($trackIds), '?'));
  $stmt = $pdo->prepare("SELECT track_id FROM track_files
    WHERE bale_file_id IS NOT NULL AND TRIM(bale_file_id) <> '' AND track_id IN ($placeholders)");
  $stmt->execute($trackIds);
  $crawled = array_fill_keys(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)), true);
  foreach ($results as &$row) {
    if (($row['wrapperType'] ?? '') !== 'track') continue;
    $trackId = (int)($row['trackId'] ?? 0);
    $row['is_crawled'] = isset($crawled[$trackId]);
    $row['bale_stream_url'] = $row['is_crawled'] ? signed_bale_url($trackId) : null;
  }
  unset($row);
  return $results;
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
  return array_slice(array_keys($tracks), 0, 300);
}
function lyrics_row($pdo, $tid) {
  $s = $pdo->prepare('SELECT lyrics_synced, lyrics_plain, lyrics_source FROM track_files WHERE track_id=?');
  $s->execute([$tid]); return $s->fetch();
}
function decorate_local_tracks($rows) {
  foreach ($rows as &$row) {
    $hasBaleFile = !empty($row['bale_file_id']);
    $row['is_crawled'] = $hasBaleFile || !empty($row['telegram_file_id']);
    $row['bale_stream_url'] = $hasBaleFile ? signed_bale_url((int)$row['track_id']) : null;
    $row['wrapperType'] = 'track';
    unset($row['bale_file_id'], $row['telegram_file_id']);
  }
  unset($row);
  return $rows;
}
function popular_tracks($pdo, $limit) {
  $s = $pdo->prepare("SELECT t.track_id,t.collection_id,t.artist_id,t.name,t.artist_name,t.collection_name,t.artwork,
      t.duration_ms,t.track_number,t.track_count,t.disc_number,t.disc_count,t.release_date,t.genre,t.explicit,t.preview_url,
      COALESCE(f.favorite_count,0) AS favorite_count,COALESCE(q.crawl_count,0) AS crawl_count,
      COALESCE(p.playlist_count,0) AS playlist_count,tf.bale_file_id,tf.telegram_file_id
    FROM tracks t
    LEFT JOIN (SELECT track_id,COUNT(*) AS favorite_count FROM favorites GROUP BY track_id) f ON f.track_id=t.track_id
    LEFT JOIN (SELECT track_id,COUNT(*) AS crawl_count FROM queue WHERE status='done' GROUP BY track_id) q ON q.track_id=t.track_id
    LEFT JOIN (SELECT track_id,COUNT(*) AS playlist_count FROM playlist_tracks GROUP BY track_id) p ON p.track_id=t.track_id
    LEFT JOIN track_files tf ON tf.track_id=t.track_id
    ORDER BY (COALESCE(f.favorite_count,0)*3 + COALESCE(q.crawl_count,0)*2 + COALESCE(p.playlist_count,0)) DESC,
      t.updated_at DESC,t.track_id DESC LIMIT ?");
  $s->bindValue(1, $limit, PDO::PARAM_INT); $s->execute();
  return decorate_local_tracks($s->fetchAll());
}
function fresh_tracks($pdo, $limit) {
  $s = $pdo->prepare("SELECT t.track_id,t.collection_id,t.artist_id,t.name,t.artist_name,t.collection_name,t.artwork,
      t.duration_ms,t.track_number,t.track_count,t.disc_number,t.disc_count,t.release_date,t.genre,t.explicit,t.preview_url,
      tf.bale_file_id,tf.telegram_file_id FROM tracks t LEFT JOIN track_files tf ON tf.track_id=t.track_id
    ORDER BY COALESCE(NULLIF(t.release_date,''), t.updated_at) DESC,t.track_id DESC LIMIT ?");
  $s->bindValue(1, $limit, PDO::PARAM_INT); $s->execute();
  return decorate_local_tracks($s->fetchAll());
}
function ai_playlist_slug($title, $suffix = '') {
  $slug = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $title), '-'));
  return substr($slug ?: 'music-mix', 0, 60) . ($suffix !== '' ? '-' . $suffix : '');
}
function ai_playlist_list($pdo, $includeExpired = false) {
  $where = $includeExpired ? '' : "WHERE expires_at IS NULL OR expires_at > CURRENT_TIMESTAMP";
  $s = $pdo->query("SELECT id,slug,title,description,icon,color,source,country,model,tracks_json,views,generated_at,expires_at
    FROM ai_playlists $where ORDER BY generated_at DESC,id DESC");
  $items = [];
  foreach ($s->fetchAll() as $row) {
    $tracks = json_decode($row['tracks_json'], true);
    $items[] = [
      'id' => $row['id'], 'slug' => $row['slug'], 'title' => $row['title'],
      'subtitle' => $row['description'], 'icon' => $row['icon'], 'color' => $row['color'],
      'source' => $row['source'], 'country' => $row['country'],
      'generatedAt' => $row['generated_at'], 'expiresAt' => $row['expires_at'],
      'track_count' => is_array($tracks) ? count($tracks) : 0, 'views' => (int)$row['views'],
    ];
  }
  $generated = $pdo->query("SELECT COUNT(*) FROM ai_playlists
    WHERE source='ai-generated' AND (expires_at IS NULL OR expires_at>CURRENT_TIMESTAMP)")->fetchColumn();
  return ['success' => true, 'lists' => $items,
    'ai_provider_configured' => $GLOBALS['cfg']['ai_playlists_api_key'] !== '',
    'can_generate' => $GLOBALS['cfg']['ai_playlists_api_key'] !== '' && (int)$generated === 0];
}
function reserve_ai_playlist_run($pdo, $runKey, $hours) {
  $s = $pdo->prepare("INSERT INTO ai_playlist_runs (run_key,updated_at) VALUES (?,CURRENT_TIMESTAMP)
    ON CONFLICT(run_key) DO UPDATE SET updated_at=CURRENT_TIMESTAMP
    WHERE datetime(ai_playlist_runs.updated_at) <= datetime('now', ?)");
  $s->execute([$runKey, '-' . (int)$hours . ' hours']);
  return $s->rowCount() > 0;
}
function ai_playlist_request($messages) {
  global $cfg;
  $url = $cfg['ai_playlists_api_url'];
  if ($cfg['ai_playlists_api_key'] === '') throw new RuntimeException('AI playlist generation is not configured');
  if (!filter_var($url, FILTER_VALIDATE_URL) || strtolower((string)parse_url($url, PHP_URL_SCHEME)) !== 'https') {
    throw new RuntimeException('AI_PLAYLISTS_API_URL must be a valid HTTPS URL');
  }
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_CONNECTTIMEOUT => 8,
    CURLOPT_TIMEOUT => 35, CURLOPT_HTTPHEADER => [
      'Authorization: Bearer ' . $cfg['ai_playlists_api_key'],
      'Content-Type: application/json',
    ],
    CURLOPT_POSTFIELDS => json_encode([
      'model' => $cfg['ai_playlists_model'], 'messages' => $messages,
      'temperature' => 0.8, 'max_tokens' => 2200,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
  ]);
  $response = curl_exec($ch);
  $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $error = curl_error($ch);
  curl_close($ch);
  if ($response === false) throw new RuntimeException('AI playlist provider request failed: ' . $error);
  $data = json_decode($response, true);
  if ($code < 200 || $code >= 300) {
    $message = is_array($data) ? ($data['error']['message'] ?? $data['message'] ?? '') : '';
    throw new RuntimeException('AI playlist provider returned HTTP ' . $code . ($message !== '' ? ': ' . $message : ''));
  }
  $content = $data['choices'][0]['message']['content'] ?? '';
  if (!is_string($content) || trim($content) === '') throw new RuntimeException('AI playlist provider returned an empty response');
  return $content;
}
function ai_playlist_resolve_track($pdo, $name, $artist) {
  global $cfg;
  $params = ['term' => trim($name . ' ' . $artist), 'media' => 'music', 'entity' => 'song',
    'limit' => 8, 'country' => $cfg['country']];
  [$code, $response] = http_get('https://itunes.apple.com/search?' . http_build_query($params), ['Accept: application/json']);
  if ($code !== 200 || !is_string($response)) throw new RuntimeException('iTunes track lookup failed with HTTP ' . $code);
  $data = json_decode($response, true);
  if (!is_array($data) || !is_array($data['results'] ?? null)) throw new RuntimeException('iTunes returned invalid track search data');
  $results = $data['results'];
  save_results($pdo, $results);
  $nameLower = mb_strtolower(trim($name));
  $artistLower = mb_strtolower(trim($artist));
  $best = null; $bestScore = 0;
  foreach ($results as $track) {
    if (($track['wrapperType'] ?? '') !== 'track') continue;
    $trackName = mb_strtolower((string)($track['trackName'] ?? ''));
    $trackArtist = mb_strtolower((string)($track['artistName'] ?? ''));
    $score = ($trackName === $nameLower ? 2 : (str_contains($trackName, $nameLower) || str_contains($nameLower, $trackName) ? 1 : 0)) +
      ($trackArtist === $artistLower ? 2 : (str_contains($trackArtist, $artistLower) || str_contains($artistLower, $trackArtist) ? 1 : 0));
    if ($score > $bestScore) { $best = $track; $bestScore = $score; }
  }
  return $bestScore >= 2 ? $best : null;
}
function save_ai_playlists($pdo, $lists, $source, $model = null, $country = 'us', $hours = 24) {
  $expires = date('Y-m-d H:i:s', time() + $hours * 3600);
  $prepared = [];
  foreach (array_slice($lists, 0, 4) as $index => $list) {
    if (!is_array($list)) continue;
    $title = trim((string)($list['title'] ?? ''));
    if ($title === '') continue;
    $tracks = []; $seen = [];
    foreach (array_slice(is_array($list['tracks'] ?? null) ? $list['tracks'] : [], 0, 8) as $track) {
      if (!is_array($track)) continue;
      $name = trim((string)($track['name'] ?? ''));
      $artist = trim((string)($track['artist'] ?? ''));
      if ($name === '' || $artist === '') continue;
      $resolved = is_array($track['resolved'] ?? null) ? $track['resolved'] :
        ai_playlist_resolve_track($pdo, $name, $artist);
      if (!$resolved) continue;
      $trackId = (string)($resolved['trackId'] ?? '');
      if ($trackId === '' || isset($seen[$trackId])) continue;
      $seen[$trackId] = true;
      $resolved['reason'] = mb_substr(trim((string)($track['reason'] ?? '')), 0, 200);
      $tracks[] = $resolved;
    }
    if (count($tracks) < 3) continue;
    $id = substr(hash('sha256', $source . '|' . $country . '|' . date('Y-m-d') . '|' . $index . '|' . $title), 0, 24);
    $slug = ai_playlist_slug($title, substr($id, 0, 6));
    $prepared[] = [
      $id, $slug, mb_substr($title, 0, 100), mb_substr(trim((string)($list['subtitle'] ?? '')), 0, 300),
      in_array($list['icon'] ?? '', ['auto_awesome','music_note','trending_up','new_releases','favorite','nightlight','local_fire_department','bolt','album'], true) ? $list['icon'] : 'auto_awesome',
      in_array($list['color'] ?? '', ['primary','info','success','warning','danger'], true) ? $list['color'] : 'primary',
      $source, $country, $model, json_encode($tracks, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $expires,
      ['id' => $id, 'title' => $title, 'track_count' => count($tracks)],
    ];
  }
  if (!$prepared) return [];
  $created = [];
  $pdo->beginTransaction();
  try {
    $stmt = $pdo->prepare("INSERT INTO ai_playlists
      (id,slug,title,description,icon,color,source,country,model,tracks_json,generated_at,expires_at)
      VALUES (?,?,?,?,?,?,?,?,?,?,CURRENT_TIMESTAMP,?)
      ON CONFLICT(id) DO UPDATE SET title=excluded.title,description=excluded.description,icon=excluded.icon,
        color=excluded.color,model=excluded.model,tracks_json=excluded.tracks_json,
        generated_at=CURRENT_TIMESTAMP,expires_at=excluded.expires_at");
    foreach ($prepared as $row) {
      $created[] = array_pop($row);
      $stmt->execute($row);
    }
    $pdo->commit();
  } catch (Throwable $e) {
    $pdo->rollBack();
    throw $e;
  }
  return $created;
}
function refresh_ai_playlists($pdo, $force = false) {
  global $cfg;
  $existing = $pdo->query("SELECT COUNT(*) FROM ai_playlists
    WHERE source='ai-generated' AND (expires_at IS NULL OR expires_at>CURRENT_TIMESTAMP)")->fetchColumn();
  if ((int)$existing > 0 && !$force) return ['cached' => true] + ai_playlist_list($pdo);
  if ($cfg['ai_playlists_api_key'] === '') throw new RuntimeException('AI playlist generation is unavailable. Configure AI_PLAYLISTS_API_KEY on the server.');
  if (!reserve_ai_playlist_run($pdo, 'ai-global', $force ? 0 : 24)) return ['cached' => true] + ai_playlist_list($pdo, true);
  try {
    $content = ai_playlist_request([
      ['role' => 'system', 'content' => 'Create exactly 3 varied music playlists, each with 4 real, well-known songs. Return only JSON: {"lists":[{"title":"...","subtitle":"...","icon":"auto_awesome","color":"primary","tracks":[{"name":"...","artist":"...","reason":"..."}]}]}. Use Material icon names such as auto_awesome, music_note, favorite, nightlight, and bolt. Do not invent tracks. Use distinct themes and avoid duplicate songs.'],
      ['role' => 'user', 'content' => 'Create fresh, diverse playlists for today, ' . date('F j, Y') . '.'],
    ]);
    $start = strpos($content, '{'); $end = strrpos($content, '}');
    $decoded = $start === false || $end === false ? null : json_decode(substr($content, $start, $end - $start + 1), true);
    if (!is_array($decoded) || !is_array($decoded['lists'] ?? null)) throw new RuntimeException('AI playlist response did not contain a valid lists array');
    $created = save_ai_playlists($pdo, $decoded['lists'], 'ai-generated', $cfg['ai_playlists_model']);
    if (!$created) throw new RuntimeException('AI response did not resolve to enough valid songs');
    $keep = array_column($created, 'id');
    $placeholders = implode(',', array_fill(0, count($keep), '?'));
    $pdo->prepare("DELETE FROM ai_playlists WHERE source='ai-generated' AND id NOT IN ($placeholders)")->execute($keep);
    return ['cached' => false, 'created' => $created] + ai_playlist_list($pdo);
  } catch (Throwable $e) {
    $pdo->prepare('DELETE FROM ai_playlist_runs WHERE run_key=?')->execute(['ai-global']);
    error_log('AI playlist generation failed: ' . $e->getMessage());
    throw $e;
  }
}
function refresh_chart_playlists($pdo, $country) {
  $country = strtolower(trim($country));
  if (!preg_match('/^[a-z]{2}$/', $country)) $country = 'us';
  if (!reserve_ai_playlist_run($pdo, 'charts-' . $country, 12)) return ['cached' => true] + ai_playlist_list($pdo);
  $feeds = [
    ['feed' => 'most-played', 'type' => 'songs', 'title' => 'Top Songs'],
    ['feed' => 'most-played', 'type' => 'albums', 'title' => 'Top Albums'],
    ['feed' => 'new-music', 'type' => 'songs', 'title' => 'New Music'],
    ['feed' => 'new-music', 'type' => 'albums', 'title' => 'New Albums'],
  ];
  $playlists = [];
  try {
    foreach ($feeds as $feed) {
      $url = 'https://rss.applemarketingtools.com/api/v2/' . $country . '/music/' . $feed['feed'] . '/25/' . $feed['type'] . '.json';
      [$code, $response] = http_get($url, ['Accept: application/json']);
      $data = $code === 200 && is_string($response) ? json_decode($response, true) : null;
      $entries = $data['feed']['results'] ?? [];
      if (!is_array($entries)) continue;
      $tracks = [];
      foreach (array_slice($entries, 0, $feed['type'] === 'albums' ? 3 : 8) as $entry) {
        if ($feed['type'] === 'albums') {
          $albumUrl = (string)($entry['url'] ?? $entry['id'] ?? '');
          if (!preg_match('/(\d+)(?:[?#].*)?$/', $albumUrl, $match)) continue;
          $albumTracks = itunes('lookup', ['id' => $match[1], 'entity' => 'song', 'limit' => 200]);
          save_results($pdo, $albumTracks);
          foreach ($albumTracks as $track) {
            if (($track['wrapperType'] ?? '') !== 'track') continue;
            $tracks[] = [
              'name' => (string)($track['trackName'] ?? ''),
              'artist' => (string)($track['artistName'] ?? ''),
              'reason' => 'Featured on a charting Apple Music album',
              'resolved' => $track,
            ];
            if (count($tracks) >= 8) break 2;
          }
          continue;
        }
        $name = trim((string)($entry['name'] ?? ''));
        $artist = trim((string)($entry['artistName'] ?? ''));
        if ($name !== '' && $artist !== '') {
          $songUrl = (string)($entry['url'] ?? $entry['id'] ?? '');
          if (preg_match('/[?&]i=(\d+)/', $songUrl, $match)) {
            $resolved = itunes('lookup', ['id' => $match[1], 'entity' => 'song']);
            save_results($pdo, $resolved);
            $resolvedTrack = null;
            foreach ($resolved as $candidate) if (($candidate['wrapperType'] ?? '') === 'track') { $resolvedTrack = $candidate; break; }
            if ($resolvedTrack) {
              $tracks[] = ['name' => $name, 'artist' => $artist,
                'reason' => 'Featured in Apple Music charts', 'resolved' => $resolvedTrack];
              continue;
            }
          }
          $tracks[] = ['name' => $name, 'artist' => $artist, 'reason' => 'Featured in Apple Music charts'];
        }
      }
      if ($tracks) $playlists[] = [
        'title' => $feed['title'] . ' · ' . strtoupper($country), 'subtitle' => 'A snapshot of Apple Music charts.',
        'icon' => $feed['feed'] === 'new-music' ? 'new_releases' : 'trending_up',
        'color' => 'primary', 'tracks' => $tracks,
      ];
    }
    if (!$playlists) throw new RuntimeException('Apple Music chart feeds returned no usable playlists');
    $created = save_ai_playlists($pdo, $playlists, 'apple-charts', null, $country, 24);
    if (!$created) throw new RuntimeException('Apple Music chart entries did not resolve to enough tracks');
    $keep = array_column($created, 'id');
    $placeholders = implode(',', array_fill(0, count($keep), '?'));
    $pdo->prepare("DELETE FROM ai_playlists WHERE source='apple-charts' AND country=? AND id NOT IN ($placeholders)")
      ->execute(array_merge([$country], $keep));
    return ['cached' => false, 'created' => $created] + ai_playlist_list($pdo);
  } catch (Throwable $e) {
    $pdo->prepare('DELETE FROM ai_playlist_runs WHERE run_key=?')->execute(['charts-' . $country]);
    error_log('Apple chart playlist refresh failed: ' . $e->getMessage());
    throw $e;
  }
}

$action = $_GET['action'] ?? '';
switch ($action) {
  case 'register': {
    $b = body(); $emailInput = $b['email'] ?? ''; $password = $b['password'] ?? '';
    if (!is_string($emailInput) || !is_string($password)) out(['error' => 'email_and_password_required'], 400);
    $email = strtolower(trim($emailInput));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254 ||
        strlen($password) < 8 || strlen($password) > 72) {
      out(['error' => 'email_and_password_required', 'message' => 'Use a valid email and a password between 8 and 72 bytes.'], 400);
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
  case 'settings': {
    $user = current_user(true);
    $s = $pdo->prepare('SELECT settings_json, updated_at FROM user_settings WHERE user_id=?');
    $s->execute([$user['id']]); $row = $s->fetch();
    out(['settings' => $row ? json_decode($row['settings_json'], true) : null,
      'updated_at' => $row['updated_at'] ?? null]);
  }
  case 'settings_save': {
    $user = current_user(true); $b = body(); $input = $b['settings'] ?? null;
    if (!is_array($input)) out(['error' => 'settings_required'], 400);
    $s = $pdo->prepare('SELECT settings_json FROM user_settings WHERE user_id=?');
    $s->execute([$user['id']]); $current = json_decode($s->fetchColumn() ?: '{}', true) ?: [];
    $settings = array_replace([
      'theme' => '', 'themeColor' => 0, 'refill' => true, 'motion' => false,
      'saveData' => false, 'sleepMinutes' => 0, 'auto' => false, 'volume' => 0.8,
    ], $current, $input);
    if (!in_array($settings['theme'], ['', 'light', 'dark', 'auto'], true) ||
        !is_numeric($settings['themeColor']) || (int)$settings['themeColor'] < 0 ||
        (int)$settings['themeColor'] > 9 || (float)$settings['themeColor'] !== (float)(int)$settings['themeColor'] ||
        !is_bool($settings['refill']) || !is_bool($settings['motion']) || !is_bool($settings['saveData']) || !is_bool($settings['auto']) ||
        !is_numeric($settings['sleepMinutes']) || !in_array((int)$settings['sleepMinutes'], [0,15,30,45,60,90], true) ||
        (float)$settings['sleepMinutes'] !== (float)(int)$settings['sleepMinutes'] ||
        !is_numeric($settings['volume']) || !is_finite((float)$settings['volume']) ||
        (float)$settings['volume'] < 0 || (float)$settings['volume'] > 1) {
      out(['error' => 'settings_invalid'], 400);
    }
    $settings = [
      'theme' => $settings['theme'], 'themeColor' => (int)$settings['themeColor'],
      'refill' => $settings['refill'], 'motion' => $settings['motion'], 'saveData' => $settings['saveData'],
      'sleepMinutes' => (int)$settings['sleepMinutes'], 'auto' => $settings['auto'],
      'volume' => (float)$settings['volume'],
    ];
    $json = json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $pdo->prepare("INSERT INTO user_settings (user_id, settings_json) VALUES (?, ?)
      ON CONFLICT(user_id) DO UPDATE SET settings_json=excluded.settings_json, updated_at=CURRENT_TIMESTAMP")
      ->execute([$user['id'], $json]);
    out(['settings' => $settings]);
  }
  case 'profile_get': {
    $viewer = current_user();
    $requestedId = filter_var($_GET['user_id'] ?? 0, FILTER_VALIDATE_INT);
    $userId = $requestedId ?: (int)($viewer['id'] ?? 0);
    if ($userId < 1) out(['error' => 'authentication_required'], 401);
    $s = $pdo->prepare('SELECT u.id,u.email,u.created_at,p.display_name,p.bio,p.avatar_url,p.is_public
      FROM users u LEFT JOIN user_profiles p ON p.user_id=u.id WHERE u.id=?');
    $s->execute([$userId]); $row = $s->fetch();
    if (!$row) out(['error' => 'profile_not_found'], 404);
    $isSelf = (int)($viewer['id'] ?? 0) === $userId;
    $isPublic = (bool)$row['is_public'];
    $count = static function (string $sql) use ($pdo, $userId): int {
      $q = $pdo->prepare($sql); $q->execute([$userId]); return (int)$q->fetchColumn();
    };
    $counts = [
      'likes' => $count('SELECT COUNT(*) FROM favorites WHERE user_id=?'),
      'following' => $count('SELECT COUNT(*) FROM followed_artists WHERE user_id=?'),
      'playlists' => $count('SELECT COUNT(*) FROM playlists WHERE user_id=?'),
    ];
    $profile = [
      'user_id' => $userId,
      'display_name' => $row['display_name'] ?: strstr($row['email'], '@', true),
      'bio' => $row['bio'] ?? '',
      'avatar_url' => $row['avatar_url'] ?? '',
      'is_public' => $isPublic,
      'created_at' => $row['created_at'],
    ];
    $items = [];
    if ($isSelf || $isPublic) {
      $tab = $_GET['tab'] ?? 'stats';
      if ($tab === 'likes') {
        $q = $pdo->prepare('SELECT t.track_id,t.collection_id,t.artist_id,t.name,t.artist_name,t.collection_name,t.artwork,
          t.duration_ms,t.preview_url,tf.bale_file_id,tf.telegram_file_id FROM favorites f
          JOIN tracks t ON t.track_id=f.track_id LEFT JOIN track_files tf ON tf.track_id=t.track_id
          WHERE f.user_id=? ORDER BY f.created_at DESC LIMIT 100');
        $q->execute([$userId]); $items = decorate_local_tracks($q->fetchAll());
      } elseif ($tab === 'following') {
        $q = $pdo->prepare('SELECT f.artist_id,COALESCE(a.name,"Artist "||f.artist_id) AS name,a.genre,a.raw
          FROM followed_artists f LEFT JOIN artists a ON a.artist_id=f.artist_id
          WHERE f.user_id=? ORDER BY f.created_at DESC LIMIT 100');
        $q->execute([$userId]); $items = $q->fetchAll();
        foreach ($items as &$item) {
          $raw = json_decode($item['raw'] ?? '', true);
          $item['artwork'] = art($raw['artworkUrl100'] ?? '');
          unset($item['raw']);
        }
        unset($item);
      } elseif ($tab === 'playlists') {
        $q = $pdo->prepare('SELECT p.id,p.name,p.updated_at,COUNT(pt.track_id) AS track_count
          FROM playlists p LEFT JOIN playlist_tracks pt ON pt.playlist_id=p.id
          WHERE p.user_id=? GROUP BY p.id ORDER BY p.updated_at DESC,p.id DESC LIMIT 100');
        $q->execute([$userId]); $items = $q->fetchAll();
      }
    } elseif (!$isSelf) {
      out(['error' => 'profile_private', 'profile' => $profile, 'is_self' => false]);
    }
    out(['profile' => $profile, 'is_self' => $isSelf, 'counts' => $counts, 'items' => $items]);
  }
  case 'profile_save': {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') out(['error' => 'method_not_allowed'], 405);
    $user = current_user(true); $b = body();
    $name = trim((string)($b['display_name'] ?? ''));
    $bio = trim((string)($b['bio'] ?? ''));
    $avatar = trim((string)($b['avatar_url'] ?? ''));
    $isPublic = $b['is_public'] ?? false;
    if (mb_strlen($name) > 60 || mb_strlen($bio) > 400 || !is_bool($isPublic)) out(['error' => 'profile_invalid'], 400);
    if ($avatar !== '' && (!filter_var($avatar, FILTER_VALIDATE_URL) || !in_array(strtolower((string)parse_url($avatar, PHP_URL_SCHEME)), ['http','https'], true) || strlen($avatar) > 2048)) {
      out(['error' => 'avatar_url_invalid'], 400);
    }
    $pdo->prepare("INSERT INTO user_profiles (user_id,display_name,bio,avatar_url,is_public)
      VALUES (?,?,?,?,?) ON CONFLICT(user_id) DO UPDATE SET display_name=excluded.display_name,
      bio=excluded.bio,avatar_url=excluded.avatar_url,is_public=excluded.is_public,updated_at=CURRENT_TIMESTAMP")
      ->execute([(int)$user['id'],$name,$bio,$avatar,$isPublic ? 1 : 0]);
    out(['ok' => true]);
  }
  case 'search': {
    $term = trim($_GET['term'] ?? ''); if ($term === '') out(['error' => 'term required'], 400);
    $entity = in_array($_GET['entity'] ?? '', ['song', 'album', 'musicArtist']) ? $_GET['entity'] : 'song';
    $res = itunes('search', ['term' => $term, 'media' => 'music', 'entity' => $entity, 'limit' => min(50, (int)($_GET['limit'] ?? 25))]);
    save_results($pdo, $res); out(['results' => slim(attach_bale_playback($pdo, $res))]);
  }
  case 'suggest': {
    $term = trim((string)($_GET['q'] ?? $_GET['term'] ?? ''));
    if (mb_strlen($term) < 2) out(['query' => $term, 'suggestions' => []]);
    $limit = min(12, max(1, (int)($_GET['limit'] ?? 8)));
    $entity = $_GET['entity'] ?? '';
    $entities = $entity === 'song' ? ['song'] : ($entity === 'artist' ? ['musicArtist'] : ['song', 'musicArtist', 'album']);
    $suggestions = []; $seen = [];
    foreach ($entities as $type) {
      $results = itunes('search', ['term' => $term, 'media' => 'music', 'entity' => $type, 'limit' => $type === 'musicArtist' ? 3 : $limit]);
      save_results($pdo, $results);
      foreach ($results as $result) {
        $wrapper = $result['wrapperType'] ?? '';
        $id = (int)($wrapper === 'track' ? ($result['trackId'] ?? 0) :
          ($wrapper === 'artist' ? ($result['artistId'] ?? 0) : ($result['collectionId'] ?? 0)));
        if ($id < 1 || isset($seen[$wrapper . ':' . $id])) continue;
        $seen[$wrapper . ':' . $id] = true;
        $suggestions[] = [
          'id' => $id, 'type' => $wrapper,
          'name' => $result['trackName'] ?? $result['collectionName'] ?? $result['artistName'] ?? '',
          'artistName' => $result['artistName'] ?? '',
          'artwork' => art($result['artworkUrl100'] ?? $result['artworkUrl60'] ?? ''),
        ];
        if (count($suggestions) >= $limit) break 2;
      }
    }
    out(['query' => $term, 'suggestions' => $suggestions]);
  }
  case 'fresh': {
    $limit = min(50, max(1, (int)($_GET['limit'] ?? 12)));
    out(['results' => fresh_tracks($pdo, $limit)]);
  }
  case 'lookup': { // id + entity=song|album ; album id -> its songs, artist id -> its albums
    $res = itunes('lookup', ['id' => (int)$_GET['id'], 'entity' => $_GET['entity'] ?? 'song', 'limit' => 200]);
    save_results($pdo, $res); out(['results' => slim(attach_bale_playback($pdo, $res))]);
  }
  case 'related_tracks':
  case 'related': {
    $trackId = (int)($_GET['track_id'] ?? $_GET['id'] ?? 0);
    if ($trackId < 1) out(['error' => 'track_id_required'], 400);
    $limit = min(50, max(1, (int)($_GET['limit'] ?? 12)));
    $trackStmt = $pdo->prepare('SELECT track_id, collection_id, artist_id, name, artist_name, genre FROM tracks WHERE track_id=?');
    $trackStmt->execute([$trackId]); $source = $trackStmt->fetch();
    if (!$source) out(['error' => 'unknown_track'], 404);
    $excludeIds = array_slice(array_values(array_unique(array_filter(array_map('intval',
      explode(',', (string)($_GET['exclude'] ?? ''))), fn($id) => $id > 0))), -500);
    $excludeIds[] = $trackId;
    $excludeIds = array_values(array_unique($excludeIds));
    $matches = []; $params = [];
    if (!empty($source['artist_id'])) { $matches[] = 't.artist_id=?'; $params[] = $source['artist_id']; }
    if (!empty($source['collection_id'])) { $matches[] = 't.collection_id=?'; $params[] = $source['collection_id']; }
    if (!empty($source['genre'])) { $matches[] = 't.genre=?'; $params[] = $source['genre']; }
    if (!$matches) out(['track_id' => $trackId, 'results' => []]);
    $excludeSql = implode(',', array_fill(0, count($excludeIds), '?'));
    $sql = "SELECT t.track_id,t.collection_id,t.artist_id,t.name,t.artist_name,t.collection_name,t.artwork,
        t.duration_ms,t.track_number,t.track_count,t.disc_number,t.disc_count,t.release_date,t.genre,t.explicit,t.preview_url,
        tf.bale_file_id,tf.telegram_file_id FROM tracks t LEFT JOIN track_files tf ON tf.track_id=t.track_id
      WHERE t.track_id NOT IN ($excludeSql) AND (" . implode(' OR ', $matches) . ")
      ORDER BY CASE WHEN t.artist_id=? THEN 0 WHEN t.collection_id=? THEN 1 ELSE 2 END,
        CASE WHEN TRIM(COALESCE(tf.bale_file_id,''))<>'' OR TRIM(COALESCE(tf.telegram_file_id,''))<>'' THEN 0 ELSE 1 END,
        t.updated_at DESC,t.track_id DESC LIMIT ?";
    $params = array_merge($excludeIds, $params, [$source['artist_id'], $source['collection_id'], $limit]);
    $s = $pdo->prepare($sql); $s->execute($params);
    $results = decorate_local_tracks($s->fetchAll());
    if (count($results) < $limit && !empty($source['artist_name'])) {
      $remote = itunes('search', [
        'term' => $source['artist_name'], 'media' => 'music', 'entity' => 'song', 'limit' => min(50, $limit * 3),
      ]);
      save_results($pdo, $remote);
      $seen = array_fill_keys($excludeIds, true);
      foreach ($results as $track) $seen[(int)$track['track_id']] = true;
      $remote = array_values(array_filter($remote, function ($track) use (&$seen) {
        if (($track['wrapperType'] ?? '') !== 'track') return false;
        $id = (int)($track['trackId'] ?? 0);
        if ($id < 1 || isset($seen[$id])) return false;
        $seen[$id] = true;
        return true;
      }));
      $results = array_merge($results, slim(attach_bale_playback($pdo, $remote)));
      $results = array_slice($results, 0, $limit);
    }
    out(['track_id' => $trackId, 'results' => $results]);
  }
  case 'artist_tracks': {
    $artistId = (int)($_GET['artist_id'] ?? $_GET['id'] ?? 0);
    if ($artistId < 1) out(['error' => 'artist_id_required'], 400);
    $allIds = tracks_for($pdo, 'artist', $artistId);
    $page = max(1, (int)($_GET['page'] ?? 1));
    $perPage = min(100, max(1, (int)($_GET['per_page'] ?? 50)));
    $pageIds = array_slice($allIds, ($page - 1) * $perPage, $perPage);
    if (!$pageIds) out(['artist_id' => $artistId, 'page' => $page, 'per_page' => $perPage,
      'total' => count($allIds), 'items' => []]);
    $placeholders = implode(',', array_fill(0, count($pageIds), '?'));
    $s = $pdo->prepare("SELECT t.track_id,t.collection_id,t.artist_id,t.name,t.artist_name,t.collection_name,t.artwork,
        t.duration_ms,t.track_number,t.track_count,t.disc_number,t.disc_count,t.release_date,t.genre,t.explicit,t.preview_url,
        tf.bale_file_id,tf.telegram_file_id FROM tracks t LEFT JOIN track_files tf ON tf.track_id=t.track_id
      WHERE t.track_id IN ($placeholders)");
    $s->execute($pageIds);
    $byId = [];
    foreach (decorate_local_tracks($s->fetchAll()) as $track) $byId[(int)$track['track_id']] = $track;
    $items = array_values(array_filter(array_map(fn($id) => $byId[(int)$id] ?? null, $pageIds)));
    out(['artist_id' => $artistId, 'page' => $page, 'per_page' => $perPage,
      'total' => count($allIds), 'items' => $items]);
  }
  case 'recently_crawled': {
    $limit = min(50, max(1, (int)($_GET['limit'] ?? 12)));
    $s = $pdo->prepare("SELECT t.track_id,t.collection_id,t.artist_id,t.name,t.artist_name,t.collection_name,t.artwork,
        t.duration_ms,t.track_number,t.track_count,t.disc_number,t.disc_count,t.release_date,t.genre,t.explicit,t.preview_url,
        MAX(q.updated_at) AS crawled_at,tf.bale_file_id,tf.telegram_file_id
      FROM queue q JOIN tracks t ON t.track_id=q.track_id
      JOIN track_files tf ON tf.track_id=t.track_id
      WHERE q.status='done' AND (
        TRIM(COALESCE(tf.bale_file_id,''))<>'' OR TRIM(COALESCE(tf.telegram_file_id,''))<>''
      )
      GROUP BY t.track_id ORDER BY crawled_at DESC,t.track_id DESC LIMIT ?");
    $s->bindValue(1, $limit, PDO::PARAM_INT); $s->execute();
    out(['items' => decorate_local_tracks($s->fetchAll())]);
  }
  case 'popular': {
    $limit = min(50, max(1, (int)($_GET['limit'] ?? 12)));
    out(['results' => popular_tracks($pdo, $limit)]);
  }
  case 'suggested_playlist': {
    $limit = min(50, max(1, (int)($_GET['limit'] ?? 20)));
    $user = current_user();
    $items = [];
    if ($user) {
      $s = $pdo->prepare("SELECT t.track_id,t.collection_id,t.artist_id,t.name,t.artist_name,t.collection_name,t.artwork,
          t.duration_ms,t.track_number,t.track_count,t.disc_number,t.disc_count,t.release_date,t.genre,t.explicit,t.preview_url,
          tf.bale_file_id,tf.telegram_file_id
        FROM tracks t LEFT JOIN track_files tf ON tf.track_id=t.track_id
        WHERE t.artist_id IN (
          SELECT artist_id FROM followed_artists WHERE user_id=?
          UNION SELECT seed.artist_id FROM favorites f JOIN tracks seed ON seed.track_id=f.track_id
            WHERE f.user_id=? AND seed.artist_id IS NOT NULL
        )
        AND NOT EXISTS (SELECT 1 FROM favorites f WHERE f.user_id=? AND f.track_id=t.track_id)
        ORDER BY CASE WHEN TRIM(COALESCE(tf.bale_file_id,''))<>'' OR TRIM(COALESCE(tf.telegram_file_id,''))<>'' THEN 0 ELSE 1 END,
          t.updated_at DESC,t.track_id DESC LIMIT ?");
      $s->bindValue(1, (int)$user['id'], PDO::PARAM_INT);
      $s->bindValue(2, (int)$user['id'], PDO::PARAM_INT);
      $s->bindValue(3, (int)$user['id'], PDO::PARAM_INT);
      $s->bindValue(4, $limit, PDO::PARAM_INT);
      $s->execute(); $items = decorate_local_tracks($s->fetchAll());
    }
    $source = $items ? 'personalized' : 'popular';
    if (!$items) $items = popular_tracks($pdo, $limit);
    out(['playlist' => ['name' => $user ? 'Made for you' : 'Suggested tracks',
      'description' => $items && $source === 'personalized' ? 'Based on artists you follow and songs you like.' : 'Popular tracks from the mman library.'],
      'source' => $source, 'items' => $items]);
  }
  case 'ai_playlists':
  case 'ai_lists': {
    out(ai_playlist_list($pdo, filter_var($_GET['include_expired'] ?? false, FILTER_VALIDATE_BOOL)));
  }
  case 'ai_playlist': {
    $id = trim((string)($_GET['id'] ?? ''));
    if ($id === '') out(['error' => 'playlist_id_required'], 400);
    $s = $pdo->prepare("SELECT id,slug,title,description,icon,color,source,country,model,tracks_json,views,generated_at,expires_at
      FROM ai_playlists WHERE id=? AND (expires_at IS NULL OR expires_at>CURRENT_TIMESTAMP)");
    $s->execute([$id]); $playlist = $s->fetch();
    if (!$playlist) out(['error' => 'playlist_not_found'], 404);
    $tracks = json_decode($playlist['tracks_json'], true);
    if (!is_array($tracks)) out(['error' => 'playlist_data_invalid'], 500);
    $tracks = attach_bale_playback($pdo, $tracks);
    $pdo->prepare('UPDATE ai_playlists SET views=views+1 WHERE id=?')->execute([$id]);
    out(['playlist' => [
      'id' => $playlist['id'], 'slug' => $playlist['slug'], 'title' => $playlist['title'],
      'description' => $playlist['description'], 'icon' => $playlist['icon'], 'color' => $playlist['color'],
      'source' => $playlist['source'], 'country' => $playlist['country'],
      'generated_at' => $playlist['generated_at'], 'expires_at' => $playlist['expires_at'],
      'views' => (int)$playlist['views'] + 1,
    ], 'items' => slim($tracks)]);
  }
  case 'ai_playlists_refresh':
  case 'ai_lists_refresh': {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') out(['error' => 'method_not_allowed'], 405);
    current_user(true);
    if (filter_var($_GET['force'] ?? false, FILTER_VALIDATE_BOOL)) need_key();
    try { out(refresh_ai_playlists($pdo, filter_var($_GET['force'] ?? false, FILTER_VALIDATE_BOOL))); }
    catch (Throwable $e) {
      error_log('AI playlist refresh failed: ' . $e->getMessage());
      out(['error' => 'ai_playlist_refresh_failed', 'message' => $e->getMessage()], 502);
    }
  }
  case 'ai_charts_refresh':
  case 'ai_lists_itunes': {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') out(['error' => 'method_not_allowed'], 405);
    current_user(true);
    try { out(refresh_chart_playlists($pdo, (string)($_GET['country'] ?? $cfg['country']))); }
    catch (Throwable $e) {
      error_log('Chart playlist refresh failed: ' . $e->getMessage());
      out(['error' => 'chart_playlist_refresh_failed', 'message' => $e->getMessage()], 502);
    }
  }
  case 'bot_queue_add': { // POST {type: track|collection|artist, id}, reserved for the PHP webhook
    need_key();
    $b = body(); $type = $b['type'] ?? ''; $id = (int)($b['id'] ?? 0);
    if (!in_array($type, ['track', 'collection', 'artist'], true) || $id < 1) out(['error' => 'bad_request'], 400);
    $ids = tracks_for($pdo, $type, $id);
    $insert = $pdo->prepare('INSERT INTO queue (user_id, track_id)
      SELECT NULL, ? WHERE NOT EXISTS (SELECT 1 FROM queue WHERE user_id IS NULL AND track_id=?)');
    $added = 0;
    $pdo->beginTransaction();
    try {
      foreach ($ids as $trackId) {
        $insert->execute([$trackId, $trackId]);
        $added += $insert->rowCount();
      }
      $pdo->commit();
    } catch (Throwable $e) {
      $pdo->rollBack();
      throw $e;
    }
    out(['added' => $added, 'track_ids' => $ids, 'limit' => 300]);
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
    $s = $pdo->prepare("SELECT q.id,q.status,q.progress,q.message,q.updated_at,t.track_id,t.name,t.artist_name,t.collection_name,t.artwork,t.preview_url,
      f.bale_message_id,f.bale_file_id,f.telegram_message_id,f.telegram_file_id FROM queue q JOIN tracks t ON t.track_id=q.track_id
      LEFT JOIN track_files f ON f.track_id=q.track_id $w ORDER BY q.id DESC LIMIT $pp OFFSET $off");
    $s->execute($p); $rows = $s->fetchAll();
    foreach ($rows as &$r) {
      $r['is_crawled'] = !empty($r['bale_file_id']);
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
  case 'artist_follow': {
    $user = current_user(true); $b = body(); $artistId = (int)($b['artist_id'] ?? 0);
    if ($artistId < 1) out(['error' => 'artist_id_required'], 400);
    $exists = $pdo->prepare('SELECT 1 FROM followed_artists WHERE user_id=? AND artist_id=?');
    $exists->execute([$user['id'], $artistId]);
    if ($exists->fetchColumn()) {
      $pdo->prepare('DELETE FROM followed_artists WHERE user_id=? AND artist_id=?')->execute([$user['id'], $artistId]);
      out(['following' => false]);
    }
    $pdo->prepare('INSERT INTO followed_artists (user_id, artist_id) VALUES (?, ?)')->execute([$user['id'], $artistId]);
    out(['following' => true]);
  }
  case 'artist_following': {
    $user = current_user(true); $artistId = (int)($_GET['artist_id'] ?? 0);
    if ($artistId < 1) out(['error' => 'artist_id_required'], 400);
    $s = $pdo->prepare('SELECT 1 FROM followed_artists WHERE user_id=? AND artist_id=?');
    $s->execute([$user['id'], $artistId]);
    out(['following' => (bool)$s->fetchColumn()]);
  }
  case 'followed_artists': {
    $user = current_user(true);
    $s = $pdo->prepare('SELECT f.artist_id, COALESCE(a.name, "Artist " || f.artist_id) AS name, a.genre, a.raw, f.created_at
      FROM followed_artists f LEFT JOIN artists a ON a.artist_id=f.artist_id
      WHERE f.user_id=? ORDER BY f.created_at DESC');
    $s->execute([$user['id']]);
    $items = $s->fetchAll();
    foreach ($items as &$item) {
      $raw = json_decode($item['raw'] ?? '', true);
      $item['artwork'] = art($raw['artworkUrl100'] ?? null);
      unset($item['raw']);
    }
    unset($item);
    out(['items' => $items]);
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
    $s = $pdo->prepare('SELECT t.track_id,t.name,t.artist_name,t.collection_name,t.artwork,t.duration_ms,t.preview_url,
      tf.bale_file_id,tf.telegram_file_id FROM favorites f JOIN tracks t ON t.track_id=f.track_id
      LEFT JOIN track_files tf ON tf.track_id=t.track_id WHERE f.user_id=? ORDER BY f.created_at DESC LIMIT ? OFFSET ?');
    $s->bindValue(1, $user['id'], PDO::PARAM_INT); $s->bindValue(2, $pp, PDO::PARAM_INT); $s->bindValue(3, $offset, PDO::PARAM_INT); $s->execute();
    $items = $s->fetchAll();
    foreach ($items as &$item) {
      $item['is_crawled'] = !empty($item['bale_file_id']);
      $item['bale_stream_url'] = $item['bale_file_id'] ? signed_bale_url((int)$item['track_id']) : null;
    }
    out(['page' => $page, 'per_page' => $pp, 'total' => $total, 'pages' => (int)ceil($total / $pp), 'items' => $items]);
  }
  case 'playlists': {
    $user = current_user(true);
    $s = $pdo->prepare('SELECT p.id, p.name, p.created_at, p.updated_at, COUNT(pt.track_id) AS track_count
      FROM playlists p LEFT JOIN playlist_tracks pt ON pt.playlist_id=p.id
      WHERE p.user_id=? GROUP BY p.id ORDER BY p.updated_at DESC, p.id DESC');
    $s->execute([$user['id']]);
    out(['items' => $s->fetchAll()]);
  }
  case 'playlist_create': {
    $user = current_user(true); $b = body(); $name = trim((string)($b['name'] ?? ''));
    if ($name === '' || mb_strlen($name) > 80) out(['error' => 'playlist_name_invalid'], 400);
    $pdo->prepare('INSERT INTO playlists (user_id, name) VALUES (?, ?)')->execute([$user['id'], $name]);
    out(['id' => (int)$pdo->lastInsertId(), 'name' => $name]);
  }
  case 'playlist_delete': {
    $user = current_user(true); $b = body(); $playlistId = (int)($b['playlist_id'] ?? 0);
    if ($playlistId < 1) out(['error' => 'playlist_id_required'], 400);
    $s = $pdo->prepare('DELETE FROM playlists WHERE id=? AND user_id=?');
    $s->execute([$playlistId, $user['id']]);
    if ($s->rowCount() < 1) out(['error' => 'playlist_not_found'], 404);
    out(['ok' => true]);
  }
  case 'playlist_add': {
    $user = current_user(true); $b = body();
    $playlistId = (int)($b['playlist_id'] ?? 0); $trackId = (int)($b['track_id'] ?? 0);
    $owned = $pdo->prepare('SELECT id FROM playlists WHERE id=? AND user_id=?');
    $owned->execute([$playlistId, $user['id']]);
    if (!$owned->fetchColumn()) out(['error' => 'playlist_not_found'], 404);
    $track = $pdo->prepare('SELECT 1 FROM tracks WHERE track_id=?');
    $track->execute([$trackId]);
    if ($trackId < 1 || !$track->fetchColumn()) out(['error' => 'unknown_track'], 404);
    $position = $pdo->prepare('SELECT COALESCE(MAX(position), -1) + 1 FROM playlist_tracks WHERE playlist_id=?');
    $position->execute([$playlistId]);
    $insert = $pdo->prepare('INSERT OR IGNORE INTO playlist_tracks (playlist_id, track_id, position) VALUES (?, ?, ?)');
    $insert->execute([$playlistId, $trackId, (int)$position->fetchColumn()]);
    $pdo->prepare("UPDATE playlists SET updated_at=CURRENT_TIMESTAMP WHERE id=? AND user_id=?")->execute([$playlistId, $user['id']]);
    out(['added' => $insert->rowCount() > 0]);
  }
  case 'playlist_remove': {
    $user = current_user(true); $b = body();
    $playlistId = (int)($b['playlist_id'] ?? 0); $trackId = (int)($b['track_id'] ?? 0);
    $s = $pdo->prepare('DELETE FROM playlist_tracks WHERE playlist_id=? AND track_id=?
      AND EXISTS (SELECT 1 FROM playlists WHERE id=? AND user_id=?)');
    $s->execute([$playlistId, $trackId, $playlistId, $user['id']]);
    if ($s->rowCount() < 1) out(['error' => 'playlist_track_not_found'], 404);
    $pdo->prepare('UPDATE playlists SET updated_at=CURRENT_TIMESTAMP WHERE id=? AND user_id=?')->execute([$playlistId, $user['id']]);
    out(['ok' => true]);
  }
  case 'playlist_tracks': {
    $user = current_user(true); $playlistId = (int)($_GET['playlist_id'] ?? 0);
    $page = max(1, (int)($_GET['page'] ?? 1)); $pp = min(100, max(1, (int)($_GET['per_page'] ?? 50)));
    $owned = $pdo->prepare('SELECT id, name FROM playlists WHERE id=? AND user_id=?');
    $owned->execute([$playlistId, $user['id']]);
    $playlist = $owned->fetch();
    if (!$playlist) out(['error' => 'playlist_not_found'], 404);
    $count = $pdo->prepare('SELECT COUNT(*) FROM playlist_tracks WHERE playlist_id=?');
    $count->execute([$playlistId]); $total = (int)$count->fetchColumn();
    $items = $pdo->prepare('SELECT t.track_id,t.name,t.artist_name,t.artist_id,t.collection_id,t.collection_name,t.artwork,
      t.duration_ms,t.track_number,t.preview_url,tf.bale_file_id FROM playlist_tracks pt
      JOIN tracks t ON t.track_id=pt.track_id LEFT JOIN track_files tf ON tf.track_id=t.track_id
      WHERE pt.playlist_id=? ORDER BY pt.position,pt.added_at LIMIT ? OFFSET ?');
    $items->bindValue(1, $playlistId, PDO::PARAM_INT);
    $items->bindValue(2, $pp, PDO::PARAM_INT);
    $items->bindValue(3, ($page - 1) * $pp, PDO::PARAM_INT);
    $items->execute(); $rows = $items->fetchAll();
    foreach ($rows as &$row) {
      $row['is_crawled'] = !empty($row['bale_file_id']);
      $row['bale_stream_url'] = $row['is_crawled'] ? signed_bale_url((int)$row['track_id']) : null;
    }
    unset($row);
    out(['playlist' => $playlist, 'page' => $page, 'per_page' => $pp, 'total' => $total,
      'pages' => (int)ceil($total / $pp), 'items' => $rows]);
  }
  case 'report_track_issue': {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') out(['error' => 'method_not_allowed'], 405);
    $user = current_user(true); $b = body();
    $trackId = filter_var($b['track_id'] ?? null, FILTER_VALIDATE_INT);
    $type = $b['type'] ?? '';
    $details = $b['details'] ?? '';
    if (!$trackId || $trackId < 1) out(['error' => 'track_id_required'], 400);
    if (!in_array($type, ['track_file', 'lyrics'], true)) out(['error' => 'report_type_invalid'], 400);
    if (!is_string($details) || strlen($details) > 1000) out(['error' => 'report_details_invalid'], 400);
    $track = $pdo->prepare('SELECT 1 FROM tracks WHERE track_id=?');
    $track->execute([$trackId]);
    if (!$track->fetchColumn()) out(['error' => 'unknown_track'], 404);
    $s = $pdo->prepare('INSERT OR IGNORE INTO track_reports (user_id, track_id, report_type, details) VALUES (?, ?, ?, ?)');
    $s->execute([(int)$user['id'], $trackId, $type, trim($details)]);
    out(['reported' => $s->rowCount() > 0]);
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
