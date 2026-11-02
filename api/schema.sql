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
CREATE TABLE IF NOT EXISTS queue (
  id INTEGER PRIMARY KEY AUTOINCREMENT, track_id INTEGER NOT NULL UNIQUE,
  status TEXT NOT NULL DEFAULT 'queued' CHECK (status IN ('queued','searching','downloading','lyrics','tagging','uploading_bale','uploading_telegram','done','failed')),
  progress INTEGER NOT NULL DEFAULT 0 CHECK (progress BETWEEN 0 AND 100), message TEXT NOT NULL DEFAULT '', attempts INTEGER NOT NULL DEFAULT 0,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS queue_status_id ON queue(status, id);
CREATE TABLE IF NOT EXISTS track_files (
  track_id INTEGER PRIMARY KEY, youtube_id TEXT, file_name TEXT, file_size INTEGER,
  bale_message_id INTEGER, telegram_message_id INTEGER, telegram_file_id TEXT,
  lyrics_synced TEXT, lyrics_plain TEXT, lyrics_source TEXT,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

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
