# mman

An iTunes catalog search and download queue, with a PHP/SQLite API and a Python worker that tags audio and publishes it to Bale and Telegram.

## Database and API

The API automatically creates its SQLite database and tables on first request. Ensure PHP 8.1+ has PDO SQLite, cURL, and mbstring enabled, and that the web server can create/write to the database directory. The default database path is `mman-data/mman.sqlite` three directories above `api/`, outside a typical `public_html/mm/` install. Set `SQLITE_PATH` explicitly if your hosting layout differs; keep the database outside the web root. Set these environment variables for the PHP process:

| Variable | Purpose |
| --- | --- |
| `SQLITE_PATH` | Optional SQLite database path; defaults to `../../../mman-data/mman.sqlite` relative to `api/` |
| `API_KEY` | Shared secret required by bot-only API actions |
| `ITUNES_COUNTRY` | Optional iTunes storefront, defaults to `us` |
| `APP_ORIGIN` | Public HTTPS origin serving the UI and API; defaults to `https://mman.ir` |
| `BALE_TOKEN` | Bale bot token used by the signed audio streaming proxy |
| `BALE_FILE_SECRET` | Long random secret used to sign expiring audio URLs; set independently from `API_KEY` |
| `BALE_CHANNEL_USERNAME`, `TELEGRAM_CHANNEL_USERNAME` | Optional usernames used to build queue links |
| `AI_PLAYLISTS_API_KEY` | Optional server-side key for the OpenAI-compatible AI playlist provider; never put it in the browser |
| `AI_PLAYLISTS_API_URL`, `AI_PLAYLISTS_MODEL` | Optional HTTPS endpoint and model; default to Groq-compatible chat completions |

The web UI is served from the project root and supports subdirectory installs such as `/mm/`. It includes Home discovery, Search, a combined Library for liked songs, downloads and crawl queue, artist/album/track navigation with expanded metadata, item action menus for tracks, artists, albums and playlists, a responsive player with a full-screen now-playing, queue and lyrics view, playlists and followed artists, personal track notes, and browser-side offline audio storage with visible transfer stages. Signed-in users can create a shareable profile with privacy controls, and report an incorrect track file or lyrics from a track page; duplicate reports for the same track and issue type are ignored. Settings include theme and accent controls, reduced motion, data saver, queue refill, sleep timer, backup/restore, and local storage management. The PWA shell is installed by the service worker, while downloaded audio remains in IndexedDB and can be exported as a device file. Users can register with an email and a password between 8 and 72 bytes. Queue entries, favorites, followed artists, playlists, profile data, and track reports are scoped to the signed-in user; worker and webhook queue actions remain protected by `API_KEY`. Artist crawls are capped at 300 tracks per request. Audio playback links are signed and expire after 24 hours. The proxy uses Bale's Telegram-compatible `getFile` and file-download endpoints, so verify those endpoints are enabled for your Bale bot. Configure both `BALE_TOKEN` and a strong `BALE_FILE_SECRET` to enable playback.

The API provides iTunes `search`, `lookup`, `suggest`, and `artist_tracks`; database-backed `fresh`, `popular`, and `recently_crawled` tracks; related-track recommendations; a personalized suggested playlist; and public AI/chart playlists through `ai_playlists`, `ai_playlist`, `ai_lists_refresh`, and `ai_lists_itunes`. AI generation is optional and requires `AI_PLAYLISTS_API_KEY`; chart mixes use Apple's public RSS feeds. Authenticated actions include `queue_add`, paginated `queue_list`, `favorites`, artist follow actions, playlist create/list/delete and track membership actions, account-synced `settings`/`settings_save`, profile read/update through `profile_get`/`profile_save`, and `report_track_issue` for incorrect track files or lyrics. Public profile activity is returned only when the owner enables profile visibility. Cached/live `lyrics` and API-key-protected `bot_queue_add`, `queue_next`, `queue_progress`, and `save_files` actions are also available.

## Telegram and Bale PHP webhook

`bot/webhook.php` serves both platforms at `https://YOUR_HOST/mm/bot/webhook.php?platform=telegram` and `...?platform=bale`. Configure these environment variables for PHP-FPM or the web server process: `API_URL`, `API_KEY`, `TG_BOT_TOKEN`, `BALE_TOKEN`, `TELEGRAM_WEBHOOK_SECRET`, and `BALE_WEBHOOK_SECRET`. Use distinct random webhook secrets of at least 32 characters. The endpoint accepts `/help`, `/search WORDS`, `/crawl TRACK_ID`, `/album ALBUM_ID`, and `/artist ARTIST_ID`. Crawls enter the same worker queue and publish through the configured Telegram/Bale channels; the webhook does not download audio itself.

Register Telegram's webhook with its secret header enabled:

```sh
curl -F 'url=https://YOUR_HOST/mm/bot/webhook.php?platform=telegram' \
	-F 'secret_token=YOUR_TELEGRAM_WEBHOOK_SECRET' \
	'https://api.telegram.org/botYOUR_TELEGRAM_BOT_TOKEN/setWebhook'
```

For Bale, register the corresponding `platform=bale` URL with Bale's webhook configuration and configure it to send `X-Bot-Api-Secret-Token` (or the Telegram-compatible `X-Telegram-Bot-Api-Secret-Token`) using `BALE_WEBHOOK_SECRET`. Keep all bot tokens and webhook secrets outside the repository.

Existing MySQL data is not migrated automatically. Export/import it into SQLite before switching a deployment that already contains data.

## GitHub Actions worker

Add the following repository Actions secrets. The workflow starts every two hours and can also be started with **Actions > mman queue worker > Run workflow**. Each run polls the queue for two hours, checking every 30 seconds even when the queue is empty, then exits before the next scheduled run. It checks all required secrets before installing dependencies. Python packages use the `setup-python` pip cache, keyed to `bot/requirements.txt`; FFmpeg is installed only if it is not already available on the runner.

| Secret | Value |
| --- | --- |
| `API_URL` | Optional API URL; defaults to `https://mman.ir/api/index.php` |
| `API_KEY` | Same value configured for the PHP API |
| `YOUTUBE_COOKIES` | Entire Netscape-format YouTube cookies file contents |
| `YOUTUBE_PROXY` | Optional reachable SOCKS5/HTTP proxy URL, such as a WARP-backed SOCKS proxy |
| `BALE_TOKEN`, `BALE_CHAT` | Bale bot token and destination channel/chat ID |
| `TG_BOT_TOKEN`, `TG_CHAT` | Telegram bot token and destination channel/chat ID |

The Telegram bot must be permitted to post in its destination channel. The worker sends audio using Telegram's Bot API; no Telethon session or Telegram application credentials are needed. Do not commit credentials or `cookies.txt` to the repository. The workflow ensures FFmpeg is installed, writes the cookies secret to a temporary runner file, and restores/saves the last successful YouTube download method using the Actions cache.

Before downloading, the worker ranks YouTube Music song results by normalized track-title and artist similarity, album-title similarity, release-year agreement when present, and duration difference. The catalog album and year are also included in the search query; weak title or overall matches are rejected instead of downloading a likely wrong recording. The worker tries eight yt-dlp YouTube client profiles, starting with the last one that succeeded; if it fails, it continues through the other seven methods. It processes two tracks concurrently by default; set `WORKER_CONCURRENCY` or pass `--concurrency` to adjust this conservatively for your host's bandwidth and CPU. In GitHub Actions, the workflow starts the same Docker-based Cloudflare WARP SOCKS proxy used by `sinlavi/musicman_crawler` when `YOUTUBE_PROXY` is not set, and verifies `warp=on` before starting the worker. If WARP cannot start, the workflow fails rather than silently switching to a direct connection. Set the optional `YOUTUBE_PROXY` secret to use your own reachable SOCKS5/HTTP proxy instead.

## Updating the hosted PHP installation

`api/update.php` is a CLI-only updater. It downloads the configured branch archive from this repository and copies the files over the installation without running `git pull`. It preserves `api/config.php`, `api/data/`, Git metadata, bot secrets/cookies, and worker state. If your preserved `api/config.php` predates the new settings, define `APP_ORIGIN`, `BALE_TOKEN`, and `BALE_FILE_SECRET` in the PHP process environment (or merge those keys from the repository config). Run it as the same system user that owns the web installation:

```sh
UPDATE_INSTALL_DIR=/home/ACCOUNT/public_html/mm \
UPDATE_BRANCH=main \
php /home/ACCOUNT/public_html/mm/api/update.php
```

`UPDATE_INSTALL_DIR` must be the absolute project directory and must already contain an `api/` directory. If omitted, the updater targets the directory containing `api/update.php`. To check for updates automatically, schedule this command with the host's cron (for example, once daily). The updater takes a lock to prevent overlapping runs. Keep the SQLite database outside the deployed project tree and back it up separately.

## One-file host installer

For a fresh shared-hosting install, copy `install.php` to the intended install directory and edit the `MMAN_INSTALLER_PASSWORD` value near the top to a unique password between 12 and 128 characters (or set it in the PHP process environment). Upload it over HTTPS, open `/mm/install.php` in a browser, enter that password, and provide the public HTTPS URL and Bale settings. The installer uses the same classic paper-and-ink style as the app. It downloads this repository's `main` archive, installs the files, creates the SQLite schema, writes `api/config.php` with owner-only permissions, and removes itself after success. It requires PHP 8.1+, cURL, PDO SQLite, PharData, and a writable install directory. Keep the database outside the public directory; the installer suggests a private path above a typical `public_html/mm/` deployment. Save the displayed API key for the worker. If installation fails, fix the reported hosting requirement and retry; it does not delete existing database rows.

## Run the worker locally

Install FFmpeg and `bot/requirements.txt`, set the worker environment variables above, and place the Netscape cookies file at `bot/cookies.txt` (or set `COOKIES`). Run one batch with:

```sh
python bot/bot.py --run-seconds 7200 --poll-seconds 30 --concurrency 2
```

Omit `--run-seconds` to keep polling continuously, or use `--max-jobs` to process a finite batch. `--concurrency` controls the number of tracks downloaded and uploaded in parallel.

## Always-on worker

GitHub-hosted Actions jobs have execution time limits and are not suitable for a permanently running queue poller. To keep a worker online continuously, run it on a VPS or server with Docker Compose. From the `bot/` directory, create a `.env` file containing `API_KEY`, `BALE_TOKEN`, `BALE_CHAT`, `TG_BOT_TOKEN`, and `TG_CHAT`; optionally set `API_URL` and `YOUTUBE_PROXY`. Put the Netscape-format YouTube cookies at `bot/cookies.txt`, then start the service:

```sh
cd bot
docker compose up --build --detach
```

The container polls every 30 seconds and restarts automatically if it exits. The default `YOUTUBE_PROXY` expects a SOCKS5 proxy on the host at port 1080 (for example, a WARP-backed proxy); override it if the proxy listens elsewhere, or set it to an empty value to use direct connections. The last successful downloader method is stored under `bot/worker-state/` and reused after container restarts. Use `docker compose logs -f` to inspect the worker and `docker compose down` to stop it. Keep `.env` and `cookies.txt` private; neither should be committed.