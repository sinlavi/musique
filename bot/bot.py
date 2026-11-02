"""mman queue worker: YouTube Music -> mp3 (+tags, lyrics) -> Bale channel -> Telegram channel.
Needs ffmpeg installed. Put your exported YouTube cookies in cookies.txt next to this file."""
import argparse, asyncio, os, re, shutil, socket, tempfile, time
from pathlib import Path
from urllib.parse import urlsplit
import requests, yt_dlp
from ytmusicapi import YTMusic
from mutagen.id3 import ID3, TIT2, TPE1, TPE2, TALB, TRCK, TPOS, TDRC, TCON, APIC, USLT, SYLT
from balethon import Client as Bale

API = os.getenv("API_URL", "https://mman.ir/api/index.php").strip()
KEY = os.getenv("API_KEY", "")
COOKIES = os.getenv("COOKIES", "cookies.txt")
BALE_TOKEN, BALE_CHAT = os.getenv("BALE_TOKEN"), os.getenv("BALE_CHAT")            # chat: channel id or @username
TG_BOT_TOKEN, TG_CHAT = os.getenv("TG_BOT_TOKEN"), os.getenv("TG_CHAT")          # bot must be channel admin
YOUTUBE_PROXY = os.getenv("YOUTUBE_PROXY", "socks5h://127.0.0.1:1080").strip()
METHOD_CACHE = Path(os.getenv(
    "DOWNLOAD_METHOD_CACHE",
    str(Path(__file__).with_name(".worker-state") / "last_download_method"),
))
DOWNLOAD_METHODS = {
    8: (["web"], "ejs:github"),
    2: (["web"], "ejs:npm"),
    3: (["web", "mweb", "android_vr"], "ejs:github"),
    4: (["mweb"], None),
    5: (["android_vr"], None),
    6: (["web"], "ejs:github"),
    7: (["mweb"], None),
    1: (["android"], None),
}
DEFAULT_METHOD_ORDER = [8, 2, 3, 4, 5, 6, 7, 1]
UA = {"User-Agent": "mman-queue-bot/1.0"}

def api(action, data=None):
    r = requests.post(f"{API}?action={action}", json=data or {}, headers={"X-API-Key": KEY}, timeout=30)
    r.raise_for_status(); return r.json()

def progress(qid, status, pct, msg=""):
    try: api("queue_progress", {"id": qid, "status": status, "progress": pct, "message": msg})
    except Exception as e: print("progress error:", e)

# ---------- YouTube Music ----------
def find_video(yt, t):
    q = f"{t['artist_name']} {t['name']}"
    res = yt.search(q, filter="songs", limit=10)
    if not res: raise RuntimeError("No YouTube Music result")
    want = (t["duration_ms"] or 0) / 1000
    def score(r):
        diff = abs((r.get("duration_seconds") or 0) - want) if want else 0
        return diff - (5 if t["name"].lower() in r["title"].lower() else 0)
    return min(res, key=score)["videoId"]

def _download_method_order():
    try:
        preferred = int(METHOD_CACHE.read_text().strip())
    except FileNotFoundError:
        preferred = None
    except (OSError, ValueError) as e:
        print(f"download method cache error: {e}")
        preferred = None
    if preferred not in DOWNLOAD_METHODS:
        return DEFAULT_METHOD_ORDER.copy()
    return [preferred] + [method for method in DEFAULT_METHOD_ORDER if method != preferred]

def _cache_download_method(method):
    try:
        METHOD_CACHE.parent.mkdir(parents=True, exist_ok=True)
        temp_path = METHOD_CACHE.with_name(METHOD_CACHE.name + ".tmp")
        temp_path.write_text(f"{method}\n")
        temp_path.replace(METHOD_CACHE)
    except OSError as e:
        print(f"could not cache working download method: {e}")

def _available_youtube_proxy():
    if not YOUTUBE_PROXY:
        return None
    try:
        parsed = urlsplit(YOUTUBE_PROXY)
        if parsed.scheme not in {"socks5", "socks5h", "http", "https"} or not parsed.hostname:
            raise ValueError("YOUTUBE_PROXY must be a socks5, socks5h, http, or https URL")
        port = parsed.port or (443 if parsed.scheme == "https" else 80 if parsed.scheme == "http" else 1080)
        with socket.create_connection((parsed.hostname, port), timeout=1):
            return YOUTUBE_PROXY
    except (OSError, ValueError) as e:
        print(f"YouTube proxy unavailable; using direct connection: {e}")
        return None

def _download_options(method, outdir, hook, proxy):
    clients, remote_component = DOWNLOAD_METHODS[method]
    opts = {
        "format": "bestaudio/best",
        "outtmpl": f"{outdir}/src.%(ext)s",
        "noplaylist": True,
        "quiet": True,
        "no_warnings": True,
        "cookiefile": COOKIES,
        "progress_hooks": [hook],
        "postprocessors": [{
            "key": "FFmpegExtractAudio",
            "preferredcodec": "mp3",
            "preferredquality": "320",
        }],
        "extractor_args": {"youtube": {"player_client": clients}},
        "proxy": proxy,
    }
    if remote_component and shutil.which("deno"):
        opts["js_runtimes"] = {"deno": {}}
        opts["remote_components"] = [remote_component]
    if method == 1:
        opts["http_headers"] = {
            "User-Agent": "Mozilla/5.0 (Linux; Android 12; SM-S906N Build/QP1A.190711.020) "
            "AppleWebKit/537.36 (KHTML, like Gecko) Chrome/112.0.0.0 Mobile Safari/537.36"
        }
    return opts

def download(vid, outdir, hook):
    proxy = _available_youtube_proxy()
    routes = [proxy, None] if proxy else [None]
    failures = []
    for method in _download_method_order():
        for route in routes:
            route_name = "WARP/SOCKS proxy" if route else "direct"
            print(f"Trying YouTube download method {method} via {route_name}")
            for path in Path(outdir).glob("src*"):
                if path.is_file():
                    path.unlink()
            try:
                options = _download_options(method, outdir, hook, route)
                with yt_dlp.YoutubeDL(options) as ydl:
                    ydl.download([f"https://music.youtube.com/watch?v={vid}"])
                mp3 = Path(outdir) / "src.mp3"
                if not mp3.is_file() or mp3.stat().st_size == 0:
                    raise RuntimeError("yt-dlp completed without producing an MP3")
                _cache_download_method(method)
                return mp3
            except Exception as e:
                failures.append(f"method {method} ({route_name}): {e}")
                print(f"YouTube download method {method} failed via {route_name}: {e}")
    raise RuntimeError("All YouTube download methods failed: " + " | ".join(failures))

# ---------- Lyrics ----------
def lrclib(t):
    p = {"track_name": t["name"], "artist_name": t["artist_name"], "album_name": t["collection_name"],
         "duration": round((t["duration_ms"] or 0) / 1000)}
    r = requests.get("https://lrclib.net/api/get", params=p, headers=UA, timeout=15)
    if r.ok: d = r.json()
    else:
        r = requests.get("https://lrclib.net/api/search", params={"track_name": t["name"], "artist_name": t["artist_name"]}, headers=UA, timeout=15)
        hits = r.json() if r.ok else []
        if not hits: return None, None
        d = min(hits, key=lambda h: abs((h.get("duration") or 0) - p["duration"]))
    return d.get("syncedLyrics"), d.get("plainLyrics")

def ytm_lyrics(yt, vid):
    try:
        lid = yt.get_watch_playlist(vid).get("lyrics")
        return yt.get_lyrics(lid).get("lyrics") if lid else None
    except Exception: return None

LRC = re.compile(r"\[(\d+):(\d+(?:\.\d+)?)\]")
def parse_lrc(s):
    out = []
    for line in s.splitlines():
        m = LRC.match(line)
        if m: out.append((LRC.sub("", line).strip(), int((int(m[1]) * 60 + float(m[2])) * 1000)))
    return out

def get_lyrics(yt, t, vid):
    synced = plain = None; src = None
    try: synced, plain = lrclib(t)
    except Exception as e: print("lrclib error:", e)
    if synced or plain: src = "lrclib"
    else:
        plain = ytm_lyrics(yt, vid); src = "ytmusic" if plain else None
    if synced and not plain: plain = "\n".join(x[0] for x in parse_lrc(synced))
    return synced, plain, src

# ---------- Tagging ----------
def tag(path, t, synced, plain, cover):
    tags = ID3()
    tags.add(TIT2(encoding=3, text=t["name"])); tags.add(TPE1(encoding=3, text=t["artist_name"]))
    tags.add(TPE2(encoding=3, text=t["artist_name"])); tags.add(TALB(encoding=3, text=t["collection_name"] or ""))
    if t["track_number"]: tags.add(TRCK(encoding=3, text=f"{t['track_number']}/{t['track_count'] or ''}"))
    if t["disc_number"]: tags.add(TPOS(encoding=3, text=f"{t['disc_number']}/{t['disc_count'] or ''}"))
    if t["release_date"]: tags.add(TDRC(encoding=3, text=str(t["release_date"])[:4]))
    if t["genre"]: tags.add(TCON(encoding=3, text=t["genre"]))
    if cover: tags.add(APIC(encoding=3, mime="image/jpeg", type=3, desc="Cover", data=cover))
    if plain: tags.add(USLT(encoding=3, lang="eng", desc="", text=plain))
    if synced: tags.add(SYLT(encoding=3, lang="eng", format=2, type=1, desc="", text=parse_lrc(synced)))
    tags.save(path, v2_version=3)

def safe(s): return re.sub(r'[\\/:*?"<>|]', "_", s).strip()[:120]

def upload_telegram(path, caption, track):
    url = f"https://api.telegram.org/bot{TG_BOT_TOKEN}/sendAudio"
    data = {
        "chat_id": TG_CHAT,
        "caption": caption,
        "title": track["name"],
        "performer": track["artist_name"],
    }
    if track["duration_ms"]:
        data["duration"] = max(1, int(track["duration_ms"]) // 1000)
    with open(path, "rb") as audio:
        response = requests.post(
            url,
            data=data,
            files={"audio": (path.name, audio, "audio/mpeg")},
            timeout=(30, 600),
        )
    result = response.json()
    if not response.ok or not result.get("ok"):
        raise RuntimeError(f"Telegram upload failed: {result.get('description', 'unknown error')}")
    message = result.get("result", {})
    message_id = message.get("message_id")
    file_id = message.get("audio", {}).get("file_id")
    if not message_id or not file_id:
        raise RuntimeError("Telegram upload response is missing the message ID or audio file ID")
    return {"message_id": message_id, "file_id": file_id}

# ---------- Job ----------
async def process(job, yt, bale):
    qid, t = job["queue_id"], job["track"]; tid = t["track_id"]
    tmp = tempfile.mkdtemp(prefix="trk_")
    try:
        progress(qid, "searching", 8, "Searching YouTube Music")
        vid = await asyncio.to_thread(find_video, yt, t)
        api("save_files", {"track_id": tid, "youtube_id": vid})

        last = {"p": 0}
        def hook(d):
            if d["status"] == "downloading" and (d.get("total_bytes") or d.get("total_bytes_estimate")):
                tot = d.get("total_bytes") or d.get("total_bytes_estimate")
                pct = 10 + int(d["downloaded_bytes"] / tot * 40)
                if pct >= last["p"] + 5: last["p"] = pct; progress(qid, "downloading", pct, "Downloading audio")
        progress(qid, "downloading", 10, "Downloading audio")
        mp3 = await asyncio.to_thread(download, vid, tmp, hook)

        progress(qid, "lyrics", 55, "Fetching lyrics")
        synced, plain, src = await asyncio.to_thread(get_lyrics, yt, t, vid)
        api("save_files", {"track_id": tid, "lyrics_synced": synced, "lyrics_plain": plain, "lyrics_source": src})

        progress(qid, "tagging", 62, "Writing metadata and lyrics")
        cover = None
        if t["artwork"]:
            r = await asyncio.to_thread(requests.get, t["artwork"], timeout=20)
            cover = r.content if r.ok else None
        final = Path(tmp) / f"{safe(t['artist_name'])} - {safe(t['name'])}.mp3"
        mp3.rename(final); await asyncio.to_thread(tag, final, t, synced, plain, cover)
        api("save_files", {"track_id": tid, "file_name": final.name, "file_size": final.stat().st_size})
        caption = f"{t['artist_name']} - {t['name']}\n{t['collection_name'] or ''}"

        progress(qid, "uploading_bale", 70, "Uploading to Bale")
        with open(final, "rb") as f:
            m = await bale.send_document(BALE_CHAT, f, caption=caption)
        api("save_files", {"track_id": tid, "bale_message_id": m.id})

        progress(qid, "uploading_telegram", 85, "Uploading to Telegram")
        telegram = await asyncio.to_thread(upload_telegram, final, caption, t)
        api("save_files", {
            "track_id": tid,
            "telegram_message_id": telegram["message_id"],
            "telegram_file_id": telegram["file_id"],
        })
        progress(qid, "done", 100, "Done")
        return True
    except Exception as e:
        print("failed:", e); progress(qid, "failed", 0, str(e))
        return False
    finally: shutil.rmtree(tmp, ignore_errors=True)

def validate_config():
    required = {
        "API_URL": API, "API_KEY": KEY, "BALE_TOKEN": BALE_TOKEN, "BALE_CHAT": BALE_CHAT,
        "TG_BOT_TOKEN": TG_BOT_TOKEN, "TG_CHAT": TG_CHAT,
    }
    missing = [name for name, value in required.items() if not value]
    if missing: raise RuntimeError("Missing required configuration: " + ", ".join(missing))
    if not Path(COOKIES).is_file(): raise RuntimeError(f"YouTube cookies file not found: {COOKIES}")

async def main(max_jobs=0, poll_seconds=5, run_seconds=0):
    validate_config()
    yt = YTMusic()
    bale = Bale(BALE_TOKEN)
    processed = 0
    failures = 0
    queue_errors = 0
    started_at = time.monotonic()
    deadline = started_at + run_seconds if run_seconds > 0 else None
    print(
        f"Worker started: max_jobs={max_jobs or 'unlimited'}, "
        f"run_seconds={run_seconds or 'unlimited'}, poll_seconds={poll_seconds}",
        flush=True,
    )
    await bale.connect()
    try:
        while (max_jobs <= 0 or processed < max_jobs) and (
            deadline is None or time.monotonic() < deadline
        ):
            try: job = (await asyncio.to_thread(api, "queue_next")).get("job")
            except Exception as e:
                queue_errors += 1
                print(f"Queue check failed: {e}", flush=True)
                if max_jobs > 0 and deadline is None: raise
                sleep_for = poll_seconds if deadline is None else min(
                    poll_seconds, max(0, deadline - time.monotonic())
                )
                if sleep_for <= 0: break
                print(f"Retrying queue check in {sleep_for:.0f}s", flush=True)
                await asyncio.sleep(sleep_for)
                continue
            if not job:
                sleep_for = poll_seconds if deadline is None else min(
                    poll_seconds, max(0, deadline - time.monotonic())
                )
                if sleep_for <= 0: break
                print(f"Queue is empty; checking again in {sleep_for:.0f}s", flush=True)
                await asyncio.sleep(sleep_for)
                continue
            track = job.get("track") or {}
            print(
                f"Processing queue_id={job.get('queue_id')} "
                f"track_id={track.get('track_id')} "
                f"{track.get('artist_name', '')} - {track.get('name', '')}",
                flush=True,
            )
            if not await process(job, yt, bale): failures += 1
            processed += 1
    finally:
        await bale.disconnect()
    elapsed = int(time.monotonic() - started_at)
    print(
        f"Worker stopped after {elapsed}s: processed={processed}, "
        f"failed={failures}, queue_errors={queue_errors}",
        flush=True,
    )
    if (max_jobs > 0 or run_seconds > 0) and failures:
        raise RuntimeError(f"{failures} queue job(s) failed")
    if (max_jobs > 0 or run_seconds > 0) and queue_errors:
        raise RuntimeError(f"Queue API failed {queue_errors} time(s) during this run")

if __name__ == "__main__":
    parser = argparse.ArgumentParser(description="Process queued tracks and upload them to configured channels.")
    parser.add_argument("--max-jobs", type=int, default=0, help="Exit after this many jobs; 0 polls forever")
    parser.add_argument("--poll-seconds", type=int, default=5, help="Delay between queue checks in continuous mode")
    parser.add_argument("--run-seconds", type=int, default=0, help="Stop polling after this many seconds; 0 runs forever")
    args = parser.parse_args()
    asyncio.run(main(max(0, args.max_jobs), max(1, args.poll_seconds), max(0, args.run_seconds)))
