"""Queue worker: YouTube Music -> mp3 (+tags, lyrics) -> Bale channel -> Telegram channel.
Needs ffmpeg installed. Put your exported YouTube cookies in cookies.txt next to this file."""
import asyncio, os, re, shutil, tempfile
from pathlib import Path
import requests, yt_dlp
from ytmusicapi import YTMusic
from mutagen.id3 import ID3, TIT2, TPE1, TPE2, TALB, TRCK, TPOS, TDRC, TCON, APIC, USLT, SYLT
from balethon import Client as Bale
from telethon import TelegramClient
from telethon.tl.types import DocumentAttributeAudio

API = os.getenv("API_URL", "https://example.com/api/index.php")
KEY = os.getenv("API_KEY", "CHANGE_ME_BOT_SECRET")
COOKIES = os.getenv("COOKIES", "cookies.txt")
BALE_TOKEN, BALE_CHAT = os.getenv("BALE_TOKEN"), os.getenv("BALE_CHAT")            # chat: channel id or @username
TG_API_ID, TG_API_HASH = int(os.getenv("TG_API_ID", 0)), os.getenv("TG_API_HASH")
TG_BOT_TOKEN, TG_CHAT = os.getenv("TG_BOT_TOKEN"), os.getenv("TG_CHAT")          # bot must be channel admin
UA = {"User-Agent": "music-queue-bot/1.0"}

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

def download(vid, outdir, hook):
    opts = {"format": "bestaudio/best", "outtmpl": f"{outdir}/src.%(ext)s", "noplaylist": True, "quiet": True,
            "cookiefile": COOKIES if Path(COOKIES).exists() else None, "progress_hooks": [hook],
            "postprocessors": [{"key": "FFmpegExtractAudio", "preferredcodec": "mp3", "preferredquality": "320"}]}
    with yt_dlp.YoutubeDL(opts) as ydl: ydl.download([f"https://music.youtube.com/watch?v={vid}"])
    return Path(outdir) / "src.mp3"

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

# ---------- Job ----------
async def process(job, yt, bale, tg):
    qid, t = job["queue_id"], job["track"]; tid = t["track_id"]
    tmp = tempfile.mkdtemp(prefix="trk_")
    try:
        progress(qid, "searching", 8, "Searching YouTube Music")
        vid = await asyncio.to_thread(find_video, yt, t)
        api("save_files", {"track_id": tid, "youtube_id": vid})

        last = {"p": 0}
        def hook(d):
            if d["status"] == "downloading" and d.get("total_bytes") or d.get("total_bytes_estimate"):
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
        m = await tg.send_file(TG_CHAT, str(final), caption=caption, thumb=None,
            attributes=[DocumentAttributeAudio(duration=(t["duration_ms"] or 0) // 1000, title=t["name"], performer=t["artist_name"])])
        api("save_files", {"track_id": tid, "telegram_message_id": m.id})
        progress(qid, "done", 100, "Done")
    except Exception as e:
        print("failed:", e); progress(qid, "failed", 0, str(e))
    finally: shutil.rmtree(tmp, ignore_errors=True)

async def main():
    yt = YTMusic()
    bale = Bale(BALE_TOKEN); await bale.connect()
    tg = TelegramClient("tg_session", TG_API_ID, TG_API_HASH); await tg.start(bot_token=TG_BOT_TOKEN)
    while True:
        try: job = (await asyncio.to_thread(api, "queue_next")).get("job")
        except Exception as e: print("queue error:", e); job = None
        if job: await process(job, yt, bale, tg)
        else: await asyncio.sleep(5)

if __name__ == "__main__": asyncio.run(main())
