# mman

An iTunes catalog search and download queue, with a PHP/SQLite API and a Python worker that tags audio and publishes it to Bale and Telegram.

## Database and API

The API automatically creates its SQLite database and tables on first request. Ensure PHP has PDO SQLite, cURL, and mbstring enabled, and that the web server can create/write to the database directory. The default database path is `mman-data/mman.sqlite` next to the project directory (outside the web root); set `SQLITE_PATH` to use a different persistent path outside the web root. Set these environment variables for the PHP process:

| Variable | Purpose |
| --- | --- |
| `SQLITE_PATH` | Optional SQLite database path; defaults to `../mman-data/mman.sqlite` relative to the project root |
| `API_KEY` | Shared secret required by bot-only API actions |
| `ITUNES_COUNTRY` | Optional iTunes storefront, defaults to `us` |
| `BALE_CHANNEL_USERNAME`, `TELEGRAM_CHANNEL_USERNAME` | Optional usernames used to build queue links |

The web UI is `web/index.html`; it uses `https://mman.ir/api/index.php`. The API provides iTunes `search` and `lookup`, `queue_add`, paginated `queue_list`, cached/live `lyrics`, and API-key-protected `queue_next`, `queue_progress`, and `save_files` actions.

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

The worker tries eight yt-dlp YouTube client profiles, starting with the last one that succeeded. In GitHub Actions, Cloudflare WARP is installed and started in local-proxy mode when `YOUTUBE_PROXY` is not set; the workflow tests the proxy before using it. If WARP setup fails, it logs a warning and downloads fall back to a direct connection. Set the optional `YOUTUBE_PROXY` secret to use your own reachable SOCKS5/HTTP proxy instead.

## Run the worker locally

Install FFmpeg and `bot/requirements.txt`, set the worker environment variables above, and place the Netscape cookies file at `bot/cookies.txt` (or set `COOKIES`). Run one batch with:

```sh
python bot/bot.py --run-seconds 7200 --poll-seconds 30
```

Omit `--run-seconds` to keep polling continuously, or use `--max-jobs` to process a finite batch.

## Always-on worker

GitHub-hosted Actions jobs have execution time limits and are not suitable for a permanently running queue poller. To keep a worker online continuously, run it on a VPS or server with Docker Compose. From the `bot/` directory, create a `.env` file containing `API_KEY`, `BALE_TOKEN`, `BALE_CHAT`, `TG_BOT_TOKEN`, and `TG_CHAT`; optionally set `API_URL` and `YOUTUBE_PROXY`. Put the Netscape-format YouTube cookies at `bot/cookies.txt`, then start the service:

```sh
cd bot
docker compose up --build --detach
```

The container polls every 30 seconds and restarts automatically if it exits. The default `YOUTUBE_PROXY` expects a SOCKS5 proxy on the host at port 1080 (for example, a WARP-backed proxy); override it if the proxy listens elsewhere, or set it to an empty value to use direct connections. The last successful downloader method is stored under `bot/worker-state/` and reused after container restarts. Use `docker compose logs -f` to inspect the worker and `docker compose down` to stop it. Keep `.env` and `cookies.txt` private; neither should be committed.