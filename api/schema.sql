CREATE TABLE artists (
  artist_id BIGINT PRIMARY KEY, name VARCHAR(255), genre VARCHAR(100), url TEXT, raw JSON,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) CHARSET utf8mb4;
CREATE TABLE collections (
  collection_id BIGINT PRIMARY KEY, artist_id BIGINT, name VARCHAR(255), artist_name VARCHAR(255),
  artwork TEXT, track_count INT, release_date DATE NULL, genre VARCHAR(100), explicit VARCHAR(30),
  copyright VARCHAR(255), raw JSON, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX(artist_id)
) CHARSET utf8mb4;
CREATE TABLE tracks (
  track_id BIGINT PRIMARY KEY, collection_id BIGINT, artist_id BIGINT, name VARCHAR(255),
  artist_name VARCHAR(255), collection_name VARCHAR(255), artwork TEXT, duration_ms INT,
  track_number INT, track_count INT, disc_number INT, disc_count INT, release_date DATE NULL,
  genre VARCHAR(100), explicit VARCHAR(30), preview_url TEXT, raw JSON,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX(collection_id), INDEX(artist_id)
) CHARSET utf8mb4;
CREATE TABLE queue (
  id BIGINT AUTO_INCREMENT PRIMARY KEY, track_id BIGINT NOT NULL UNIQUE,
  status ENUM('queued','searching','downloading','lyrics','tagging','uploading_bale','uploading_telegram','done','failed') DEFAULT 'queued',
  progress TINYINT UNSIGNED DEFAULT 0, message VARCHAR(500) DEFAULT '', attempts INT DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX(status, id)
) CHARSET utf8mb4;
CREATE TABLE track_files (
  track_id BIGINT PRIMARY KEY, youtube_id VARCHAR(20), file_name VARCHAR(255), file_size BIGINT,
  bale_message_id BIGINT NULL, telegram_message_id BIGINT NULL,
  lyrics_synced MEDIUMTEXT NULL, lyrics_plain MEDIUMTEXT NULL, lyrics_source VARCHAR(20) NULL,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) CHARSET utf8mb4;
