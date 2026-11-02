FROM python:3.12-slim

RUN apt-get update \
    && apt-get install --yes --no-install-recommends ffmpeg \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app
COPY requirements.txt .
RUN python -m pip install --no-cache-dir --requirement requirements.txt
COPY bot.py .

ENV COOKIES=/run/secrets/youtube_cookies
CMD ["python", "bot.py", "--poll-seconds", "30"]
