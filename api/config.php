<?php
return [
  'sqlite_path' => getenv('SQLITE_PATH') ?: dirname(__DIR__, 3) . '/mman-data/mman.sqlite',
  'api_key' => getenv('API_KEY') ?: '',
  'app_origin' => getenv('APP_ORIGIN') ?: 'https://mman.ir',
  'bale_token' => getenv('BALE_TOKEN') ?: '',
  'bale_file_secret' => getenv('BALE_FILE_SECRET') ?: '',
  'update_branch' => getenv('UPDATE_BRANCH') ?: 'main',
  'country' => getenv('ITUNES_COUNTRY') ?: 'us',
  'bale_channel_username' => getenv('BALE_CHANNEL_USERNAME') ?: '',
  'telegram_channel_username' => getenv('TELEGRAM_CHANNEL_USERNAME') ?: '',
];
