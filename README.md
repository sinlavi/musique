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

Add the following repository Actions secrets. The workflow runs every two hours and can also be started with **Actions > mman queue worker > Run workflow**. Each run handles at most ten jobs and exits when the queue is empty.

| Secret | Value |
| --- | --- |
| `API_URL` | Optional API URL; defaults to `https://mman.ir/api/index.php` |
| `API_KEY` | Same value configured for the PHP API |
| `YOUTUBE_COOKIES` | Entire Netscape-format YouTube cookies file contents |
| `BALE_TOKEN`, `BALE_CHAT` | Bale bot token and destination channel/chat ID |
| `TG_BOT_TOKEN`, `TG_CHAT` | Telegram bot token and destination channel/chat ID |

The Telegram bot must be permitted to post in its destination channel. The worker sends audio using Telegram's Bot API; no Telethon session or Telegram application credentials are needed. Do not commit credentials or `cookies.txt` to the repository. The workflow installs FFmpeg and writes the cookies secret to a temporary runner file.

## Run the worker locally

Install FFmpeg and `bot/requirements.txt`, set the worker environment variables above, and place the Netscape cookies file at `bot/cookies.txt` (or set `COOKIES`). Run one batch with:

```sh
python bot/bot.py --max-jobs 10
```

Omit `--max-jobs` to keep polling continuously.