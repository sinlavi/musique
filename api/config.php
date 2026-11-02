<?php
return [
  'sqlite_path' => getenv('SQLITE_PATH') ?: dirname(__DIR__, 2) . '/mman-data/mman.sqlite',
  'api_key' => getenv('API_KEY') ?: '',
  'country' => getenv('ITUNES_COUNTRY') ?: 'us',
  'bale_channel_username' => getenv('BALE_CHANNEL_USERNAME') ?: '',
  'telegram_channel_username' => getenv('TELEGRAM_CHANNEL_USERNAME') ?: '',
];
