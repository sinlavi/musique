CREATE TABLE IF NOT EXISTS artists (
  artist_id INTEGER PRIMARY KEY, name TEXT, genre TEXT, url TEXT, raw TEXT,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS collections (
  collection_id INTEGER PRIMARY KEY, artist_id INTEGER, name TEXT, artist_name TEXT,
  artwork TEXT, track_count INTEGER, release_date TEXT, genre TEXT, explicit TEXT,
  copyright TEXT, raw TEXT, updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS collections_artist_id ON collections(artist_id);
CREATE TABLE IF NOT EXISTS tracks (
  track_id INTEGER PRIMARY KEY, collection_id INTEGER, artist_id INTEGER, name TEXT,
  artist_name TEXT, collection_name TEXT, artwork TEXT, duration_ms INTEGER,
  track_number INTEGER, track_count INTEGER, disc_number INTEGER, disc_count INTEGER, release_date TEXT,
  genre TEXT, explicit TEXT, preview_url TEXT, raw TEXT,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS tracks_collection_id ON tracks(collection_id);
CREATE INDEX IF NOT EXISTS tracks_artist_id ON tracks(artist_id);
CREATE TABLE IF NOT EXISTS users (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  email TEXT NOT NULL COLLATE NOCASE UNIQUE,
  password_hash TEXT NOT NULL,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS sessions (
  token_hash TEXT PRIMARY KEY,
  user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  expires_at TEXT NOT NULL,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS sessions_expires_at ON sessions(expires_at);
CREATE TABLE IF NOT EXISTS user_settings (
  user_id INTEGER PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
  settings_json TEXT NOT NULL DEFAULT '{}',
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS user_profiles (
  user_id INTEGER PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
  display_name TEXT NOT NULL DEFAULT '',
  bio TEXT NOT NULL DEFAULT '',
  avatar_url TEXT NOT NULL DEFAULT '',
  is_public INTEGER NOT NULL DEFAULT 0 CHECK (is_public IN (0,1)),
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS ai_playlists (
  id TEXT PRIMARY KEY,
  slug TEXT NOT NULL UNIQUE,
  title TEXT NOT NULL,
  description TEXT NOT NULL DEFAULT '',
  icon TEXT NOT NULL DEFAULT 'auto_awesome',
  color TEXT NOT NULL DEFAULT 'primary',
  source TEXT NOT NULL,
  country TEXT NOT NULL DEFAULT 'us',
  model TEXT,
  tracks_json TEXT NOT NULL,
  views INTEGER NOT NULL DEFAULT 0,
  generated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at TEXT
);
CREATE INDEX IF NOT EXISTS ai_playlists_fresh ON ai_playlists(expires_at, generated_at DESC);
CREATE TABLE IF NOT EXISTS ai_playlist_runs (
  run_key TEXT PRIMARY KEY,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS queue (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER REFERENCES users(id) ON DELETE CASCADE,
  track_id INTEGER NOT NULL,
  status TEXT NOT NULL DEFAULT 'queued' CHECK (status IN ('queued','searching','downloading','lyrics','tagging','uploading_bale','uploading_telegram','done','failed')),
  progress INTEGER NOT NULL DEFAULT 0 CHECK (progress BETWEEN 0 AND 100), message TEXT NOT NULL DEFAULT '', attempts INTEGER NOT NULL DEFAULT 0,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE(user_id, track_id)
);
CREATE INDEX IF NOT EXISTS queue_status_id ON queue(status, id);
CREATE TABLE IF NOT EXISTS favorites (
  user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  track_id INTEGER NOT NULL REFERENCES tracks(track_id) ON DELETE CASCADE,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY(user_id, track_id)
);
CREATE TABLE IF NOT EXISTS followed_artists (
  user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  artist_id INTEGER NOT NULL,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY(user_id, artist_id)
);
CREATE INDEX IF NOT EXISTS followed_artists_user_created ON followed_artists(user_id, created_at DESC);
CREATE TABLE IF NOT EXISTS playlists (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  name TEXT NOT NULL,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS playlists_user_updated ON playlists(user_id, updated_at DESC);
CREATE TABLE IF NOT EXISTS playlist_tracks (
  playlist_id INTEGER NOT NULL REFERENCES playlists(id) ON DELETE CASCADE,
  track_id INTEGER NOT NULL REFERENCES tracks(track_id) ON DELETE CASCADE,
  position INTEGER NOT NULL,
  added_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY(playlist_id, track_id)
);
CREATE INDEX IF NOT EXISTS playlist_tracks_order ON playlist_tracks(playlist_id, position, added_at);
CREATE TABLE IF NOT EXISTS track_files (
  track_id INTEGER PRIMARY KEY, youtube_id TEXT, file_name TEXT, file_size INTEGER,
  bale_message_id INTEGER, bale_file_id TEXT, telegram_message_id INTEGER, telegram_file_id TEXT,
  lyrics_synced TEXT, lyrics_plain TEXT, lyrics_source TEXT,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS track_reports (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  track_id INTEGER NOT NULL REFERENCES tracks(track_id) ON DELETE CASCADE,
  report_type TEXT NOT NULL CHECK (report_type IN ('track_file','lyrics')),
  details TEXT NOT NULL DEFAULT '',
  status TEXT NOT NULL DEFAULT 'open' CHECK (status IN ('open','reviewed','resolved')),
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE(user_id, track_id, report_type)
);
CREATE INDEX IF NOT EXISTS track_reports_status_created ON track_reports(status, created_at);

CREATE TRIGGER IF NOT EXISTS artists_updated_at AFTER UPDATE ON artists
WHEN NEW.updated_at = OLD.updated_at
BEGIN UPDATE artists SET updated_at = CURRENT_TIMESTAMP WHERE artist_id = OLD.artist_id; END;
CREATE TRIGGER IF NOT EXISTS collections_updated_at AFTER UPDATE ON collections
WHEN NEW.updated_at = OLD.updated_at
BEGIN UPDATE collections SET updated_at = CURRENT_TIMESTAMP WHERE collection_id = OLD.collection_id; END;
CREATE TRIGGER IF NOT EXISTS tracks_updated_at AFTER UPDATE ON tracks
WHEN NEW.updated_at = OLD.updated_at
BEGIN UPDATE tracks SET updated_at = CURRENT_TIMESTAMP WHERE track_id = OLD.track_id; END;
CREATE TRIGGER IF NOT EXISTS queue_updated_at AFTER UPDATE ON queue
WHEN NEW.updated_at = OLD.updated_at
BEGIN UPDATE queue SET updated_at = CURRENT_TIMESTAMP WHERE id = OLD.id; END;
CREATE TRIGGER IF NOT EXISTS track_files_updated_at AFTER UPDATE ON track_files
WHEN NEW.updated_at = OLD.updated_at
BEGIN UPDATE track_files SET updated_at = CURRENT_TIMESTAMP WHERE track_id = OLD.track_id; END;
