<?php
declare(strict_types=1);

const MMAN_INSTALLER_PASSWORD = 'CHANGE-THIS-TO-A-LONG-RANDOM-SECRET';
const MMAN_INSTALL_BRANCH = 'main';
const MMAN_INSTALL_MAX_ARCHIVE_BYTES = 100000000;

ini_set('display_errors', '0');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');

$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
  (($_SERVER['SERVER_PORT'] ?? '') === '443');
$requestHost = strtolower((string)parse_url('http://' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST));
$isLocalRequest = in_array($requestHost, ['localhost', '127.0.0.1', '::1'], true);
if (!$isHttps && !$isLocalRequest) {
  http_response_code(403);
  exit('Open the installer over HTTPS.');
}
session_set_cookie_params([
  'httponly' => true,
  'secure' => $isHttps,
  'samesite' => 'Strict',
  'path' => '/',
]);
session_start();

function h(string $value): string {
  return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function remove_install_workspace(string $path): void {
  if (!file_exists($path) && !is_link($path)) return;
  if (!is_dir($path) || is_link($path)) {
    @unlink($path);
    return;
  }
  foreach (new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS) as $entry) {
    remove_install_workspace($entry->getPathname());
  }
  @rmdir($path);
}

function preserve_install_path(string $relative): bool {
  return $relative === 'api/config.php' ||
    $relative === 'install.php' ||
    $relative === '.git' || str_starts_with($relative, '.git/') ||
    $relative === 'api/data' || str_starts_with($relative, 'api/data/') ||
    $relative === 'bot/.env' || $relative === 'bot/cookies.txt' ||
    $relative === 'bot/.worker-state' || str_starts_with($relative, 'bot/.worker-state/') ||
    $relative === 'bot/worker-state' || str_starts_with($relative, 'bot/worker-state/');
}

function copy_release(string $source, string $target): int {
  $copied = 0;
  $iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
  );
  foreach ($iterator as $entry) {
    $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($entry->getPathname(), strlen($source) + 1));
    $segments = explode('/', $relative);
    if ($relative === '' || str_starts_with($relative, '/') ||
        in_array('..', $segments, true) || in_array('.', $segments, true)) {
      throw new RuntimeException('The downloaded archive contains an unsafe path.');
    }
    if (preserve_install_path($relative)) continue;
    if ($entry->isLink()) {
      throw new RuntimeException('The release contains an unexpected symbolic link.');
    }
    if (!$entry->isDir() && !$entry->isFile()) {
      throw new RuntimeException('The release contains an unsupported file type.');
    }

    $destination = $target;
    foreach ($segments as $index => $segment) {
      $destination .= DIRECTORY_SEPARATOR . $segment;
      if (is_link($destination)) {
        throw new RuntimeException('A destination path is a symbolic link: ' . $relative);
      }
      if ($index < count($segments) - 1 || $entry->isDir()) {
        if (!is_dir($destination) && !mkdir($destination, 0755) && !is_dir($destination)) {
          throw new RuntimeException('Could not create destination directory: ' . $relative);
        }
      }
    }
    if ($entry->isDir()) continue;

    $temporary = $destination . '.install-' . bin2hex(random_bytes(5));
    if (!copy($entry->getPathname(), $temporary) || !rename($temporary, $destination)) {
      @unlink($temporary);
      throw new RuntimeException('Could not install file: ' . $relative);
    }
    $copied++;
  }
  return $copied;
}

function install_project(array $input): array {
  if (!extension_loaded('curl') || !extension_loaded('pdo_sqlite') || !class_exists(PharData::class)) {
    throw new RuntimeException('This host needs PHP cURL, PDO SQLite, and PharData enabled.');
  }

  $targetInput = trim((string)($input['target'] ?? ''));
  if ($targetInput === '' || $targetInput[0] !== DIRECTORY_SEPARATOR) {
    throw new RuntimeException('Install directory must be an existing absolute path.');
  }
  $target = realpath($targetInput);
  if ($target === false || !is_dir($target) || $target === DIRECTORY_SEPARATOR || !is_writable($target)) {
    throw new RuntimeException('Install directory must exist and be writable by PHP.');
  }

  $origin = rtrim(trim((string)($input['app_origin'] ?? '')), '/');
  $originParts = parse_url($origin);
  if (!is_array($originParts) || ($originParts['scheme'] ?? '') !== 'https' ||
      empty($originParts['host']) || isset($originParts['user']) || isset($originParts['pass']) ||
      isset($originParts['query']) || isset($originParts['fragment']) ||
      !preg_match('#^(?:/[A-Za-z0-9._~/-]*)?$#', $originParts['path'] ?? '') ||
      preg_match('#(?:^|/)\.\.(?:/|$)#', $originParts['path'] ?? '')) {
    throw new RuntimeException('Public site URL must be an HTTPS origin, such as https://example.com/mm.');
  }

  $apiKey = trim((string)($input['api_key'] ?? ''));
  if ($apiKey === '') $apiKey = bin2hex(random_bytes(32));
  if (strlen($apiKey) < 32 || strlen($apiKey) > 512) {
    throw new RuntimeException('API key must be at least 32 characters long.');
  }
  $baleToken = trim((string)($input['bale_token'] ?? ''));
  $baleSecret = trim((string)($input['bale_file_secret'] ?? ''));
  if ($baleSecret === '') $baleSecret = bin2hex(random_bytes(32));
  if (strlen($baleSecret) < 32 || strlen($baleSecret) > 512) {
    throw new RuntimeException('Bale signing secret must be at least 32 characters long.');
  }
  $sqlitePath = trim((string)($input['sqlite_path'] ?? ''));
  if ($sqlitePath === '') $sqlitePath = dirname($target, 2) . '/mman-data/mman.sqlite';
  if ($sqlitePath[0] !== DIRECTORY_SEPARATOR || str_contains($sqlitePath, "\0")) {
    throw new RuntimeException('SQLite path must be an absolute path.');
  }
  $country = strtolower(trim((string)($input['country'] ?? 'us')));
  if (!preg_match('/^[a-z]{2}$/', $country)) throw new RuntimeException('Storefront country must be a two-letter code.');
  $baleChannel = trim((string)($input['bale_channel_username'] ?? ''), " \t\n\r\0\x0B@/");
  $telegramChannel = trim((string)($input['telegram_channel_username'] ?? ''), " \t\n\r\0\x0B@/");

  $work = sys_get_temp_dir() . '/mman-install-' . bin2hex(random_bytes(10));
  if (!mkdir($work, 0700, true)) throw new RuntimeException('Could not create a temporary install workspace.');
  try {
    $archivePath = $work . '/source.tar.gz';
    $archive = fopen($archivePath, 'wb');
    if ($archive === false) throw new RuntimeException('Could not create a temporary download file.');
    $bytes = 0;
    $curl = curl_init('https://codeload.github.com/sinlavi/musique/tar.gz/refs/heads/' . rawurlencode(MMAN_INSTALL_BRANCH));
    curl_setopt_array($curl, [
      CURLOPT_FILE => $archive,
      CURLOPT_FOLLOWLOCATION => true,
      CURLOPT_MAXREDIRS => 3,
      CURLOPT_CONNECTTIMEOUT => 15,
      CURLOPT_TIMEOUT => 180,
      CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
      CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
      CURLOPT_USERAGENT => 'mman-host-installer/1.0',
      CURLOPT_NOPROGRESS => false,
      CURLOPT_PROGRESSFUNCTION => static function ($resource, float $downloadTotal, float $downloaded) use (&$bytes): int {
        $bytes = (int)$downloaded;
        return $downloaded > MMAN_INSTALL_MAX_ARCHIVE_BYTES ? 1 : 0;
      },
    ]);
    $downloaded = curl_exec($curl);
    $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $curlError = curl_error($curl);
    curl_close($curl);
    fclose($archive);
    if ($downloaded !== true || $status !== 200 || $bytes > MMAN_INSTALL_MAX_ARCHIVE_BYTES ||
        !is_file($archivePath) || filesize($archivePath) < 1024) {
      throw new RuntimeException('Could not download the project archive (HTTP ' . $status . '): ' . $curlError);
    }

    $tar = new PharData($archivePath);
    $tar->extractTo($work . '/release', null, true);
    $roots = glob($work . '/release/musique-*', GLOB_ONLYDIR);
    if (!$roots || count($roots) !== 1 || !is_file($roots[0] . '/api/schema.sql')) {
      throw new RuntimeException('The GitHub archive did not have the expected project layout.');
    }
    $copied = copy_release($roots[0], $target);

    $config = [
      'sqlite_path' => $sqlitePath,
      'api_key' => $apiKey,
      'app_origin' => $origin,
      'bale_token' => $baleToken,
      'bale_file_secret' => $baleSecret,
      'update_branch' => MMAN_INSTALL_BRANCH,
      'country' => $country,
      'bale_channel_username' => $baleChannel,
      'telegram_channel_username' => $telegramChannel,
    ];
    $configPhp = "<?php\nreturn " . var_export($config, true) . ";\n";
    $configPath = $target . '/api/config.php';
    $configTemp = $configPath . '.install-' . bin2hex(random_bytes(5));
    if (file_put_contents($configTemp, $configPhp, LOCK_EX) === false ||
        !chmod($configTemp, 0600) || !rename($configTemp, $configPath)) {
      @unlink($configTemp);
      throw new RuntimeException('Could not securely write api/config.php.');
    }

    $databaseDirectory = dirname($sqlitePath);
    if (!is_dir($databaseDirectory) &&
        !mkdir($databaseDirectory, 0770, true) && !is_dir($databaseDirectory)) {
      throw new RuntimeException('Could not create the SQLite database directory.');
    }
    $pdo = new PDO('sqlite:' . $sqlitePath, null, null, [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_TIMEOUT => 5,
    ]);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $schema = file_get_contents($target . '/api/schema.sql');
    if ($schema === false) throw new RuntimeException('Could not read the installed SQLite schema.');
    $pdo->exec($schema);

    $starter = $target . '/index.php';
    if (!is_file($starter) && file_put_contents(
      $starter,
      "<?php\n\$index = __DIR__ . '/web/index.html';\nif (!is_file(\$index)) { http_response_code(503); exit('mman is not installed correctly.'); }\nheader('Content-Type: text/html; charset=utf-8');\nreadfile(\$index);\n"
    ) === false) {
      throw new RuntimeException('Project files were copied, but the site entry point could not be created.');
    }
    @chmod($sqlitePath, 0600);
    return [
      'copied' => $copied,
      'target' => $target,
      'api_key' => $apiKey,
      'sqlite_path' => $sqlitePath,
      'api_url' => $origin . '/api/index.php',
    ];
  } finally {
    remove_install_workspace($work);
  }
}

$configuredPassword = getenv('MMAN_INSTALLER_PASSWORD') ?: MMAN_INSTALLER_PASSWORD;
$configuredPassword = is_string($configuredPassword) ? $configuredPassword : '';
$defaultOrigin = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'example.com');
$scriptPath = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
if ($scriptPath !== '/' && $scriptPath !== '.') $defaultOrigin .= $scriptPath;
$targetDefault = realpath(__DIR__) ?: __DIR__;
$csrf = $_SESSION['mman_installer_csrf'] ??= bin2hex(random_bytes(32));
$message = '';
$result = null;

if (($_SESSION['mman_installer_failures'] ?? 0) >= 5) {
  http_response_code(429);
  $message = 'Too many failed attempts. Wait for the session to expire before trying again.';
} elseif (hash_equals(MMAN_INSTALLER_PASSWORD, 'CHANGE-THIS-TO-A-LONG-RANDOM-SECRET') &&
    getenv('MMAN_INSTALLER_PASSWORD') === false) {
  $message = 'Before uploading this file, replace MMAN_INSTALLER_PASSWORD in the source with a unique random password.';
} elseif (strlen($configuredPassword) < 32) {
  $message = 'Installer password must be at least 32 characters.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $submittedCsrf = is_string($_POST['csrf'] ?? null) ? $_POST['csrf'] : '';
  $submittedPassword = is_string($_POST['installer_password'] ?? null) ? $_POST['installer_password'] : '';
  if (!hash_equals($csrf, $submittedCsrf)) {
    $message = 'The form expired. Reload the page and try again.';
  } elseif ($configuredPassword === '' || !hash_equals($configuredPassword, $submittedPassword)) {
    $_SESSION['mman_installer_failures'] = ($_SESSION['mman_installer_failures'] ?? 0) + 1;
    $message = 'Installer password is incorrect.';
  } elseif (($_POST['confirm_install'] ?? '') !== 'yes') {
    $message = 'Confirm the installation checkbox before continuing.';
  } else {
    try {
      $_SESSION['mman_installer_failures'] = 0;
      $result = install_project($_POST);
      $targetInstaller = $result['target'] . '/install.php';
      if (is_file($targetInstaller)) @unlink($targetInstaller);
      $result['installer_removed'] = @unlink(__FILE__);
      $_SESSION['mman_installer_csrf'] = bin2hex(random_bytes(32));
      $csrf = $_SESSION['mman_installer_csrf'];
    } catch (Throwable $e) {
      error_log('mman installer failed: ' . $e->getMessage());
      $message = $e->getMessage();
    }
  }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>mman host installer</title>
<style>
body{margin:0;background:#f3f0e8;color:#252c26;font:15px/1.55 system-ui,sans-serif}.wrap{max-width:760px;margin:36px auto;padding:0 18px 50px}h1{font:32px Georgia,serif;color:#244331}h2{font:22px Georgia,serif}.panel{background:#fffdf8;border:1px solid #d9d4c7;padding:22px;margin:18px 0}.notice{padding:12px;background:#fff2d6;border:1px solid #ddbd77}.error{padding:12px;background:#fbe9e6;border:1px solid #cc8d80}.success{padding:12px;background:#e8f4e9;border:1px solid #8bb090}label{display:block;font-weight:600;margin:12px 0 5px}input{width:100%;padding:10px;border:1px solid #c9c4b8;border-radius:3px;box-sizing:border-box;font:inherit}input[type=checkbox]{width:auto;margin-right:8px}.btn{background:#365d48;color:white;border:0;border-radius:3px;padding:11px 16px;font:inherit;cursor:pointer;margin-top:14px}.hint{color:#77796f;font-size:13px}.key{word-break:break-all;background:#eeeae0;padding:10px;font-family:monospace}
</style>
</head>
<body><main class="wrap">
<h1>mman installer</h1>
<p>Install the current <code>sinlavi/musique</code> release into your hosting account. Requirements: PHP 8.1+, cURL, PDO SQLite, PharData, HTTPS, and a writable target directory.</p>
<?php if ($message !== ''): ?><div class="<?= str_contains($message, 'incorrect') || str_contains($message, 'failed') || str_contains($message, 'Too many') ? 'error' : 'notice' ?>"><?= h($message) ?></div><?php endif; ?>
<?php if ($result !== null): ?>
<section class="panel success">
  <h2>Installation complete</h2>
  <p>Installed <?= (int)$result['copied'] ?> files in <code><?= h($result['target']) ?></code>.</p>
  <p>Database: <code><?= h($result['sqlite_path']) ?></code></p>
  <p>Worker API URL: <code><?= h($result['api_url']) ?></code></p>
  <p>API key for your worker (save it as the GitHub Actions <code>API_KEY</code> secret):</p>
  <div class="key"><?= h($result['api_key']) ?></div>
  <p>Set the same API key in <code>bot/.env</code> for your always-on worker. The installer removes itself and any copy in the installed directory<?= !empty($result['installer_removed']) ? '.' : '; if it could not delete itself, remove install.php manually.' ?></p>
  <p><a href="./">Open mman</a></p>
</section>
<?php elseif (!str_contains($message, 'Before uploading')): ?>
<form class="panel" method="post" autocomplete="off">
  <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
  <label for="installer_password">Installer password</label>
  <input id="installer_password" name="installer_password" type="password" required autocomplete="current-password">
  <label for="target">Absolute install directory</label>
  <input id="target" name="target" required value="<?= h((string)($_POST['target'] ?? $targetDefault)) ?>">
  <p class="hint">The directory must already exist and be writable by PHP. Usually this is the folder where you uploaded install.php, such as /home/ACCOUNT/public_html/mm.</p>
  <label for="app_origin">Public site URL</label>
  <input id="app_origin" name="app_origin" type="url" required value="<?= h((string)($_POST['app_origin'] ?? $defaultOrigin)) ?>" placeholder="https://example.com/mm">
  <label for="sqlite_path">SQLite database path</label>
  <input id="sqlite_path" name="sqlite_path" value="<?= h((string)($_POST['sqlite_path'] ?? '')) ?>" placeholder="Leave blank for the suggested private path">
  <p class="hint">Keep the database outside the public web directory. Blank uses a directory above the install path.</p>
  <label for="api_key">Worker API key</label>
  <input id="api_key" name="api_key" type="password" minlength="32" value="<?= h((string)($_POST['api_key'] ?? '')) ?>" placeholder="Leave blank to generate a random key">
  <label for="bale_token">Bale bot token</label>
  <input id="bale_token" name="bale_token" type="password" value="<?= h((string)($_POST['bale_token'] ?? '')) ?>" autocomplete="new-password">
  <label for="bale_file_secret">Bale playback signing secret</label>
  <input id="bale_file_secret" name="bale_file_secret" type="password" minlength="32" value="<?= h((string)($_POST['bale_file_secret'] ?? '')) ?>" placeholder="Leave blank to generate a random secret">
  <label for="bale_channel_username">Bale public channel username (optional)</label>
  <input id="bale_channel_username" name="bale_channel_username" value="<?= h((string)($_POST['bale_channel_username'] ?? '')) ?>">
  <label for="telegram_channel_username">Telegram public channel username (optional)</label>
  <input id="telegram_channel_username" name="telegram_channel_username" value="<?= h((string)($_POST['telegram_channel_username'] ?? '')) ?>">
  <label for="country">iTunes storefront country</label>
  <input id="country" name="country" maxlength="2" value="<?= h((string)($_POST['country'] ?? 'us')) ?>">
  <label><input type="checkbox" name="confirm_install" value="yes" required>I have a backup of any existing installation and authorize copying project files into this directory.</label>
  <button class="btn" type="submit">Download and install mman</button>
</form>
<?php endif; ?>
<p class="hint">The installer downloads only from this project's GitHub archive. Existing SQLite data, cookies, and worker state are preserved. The API configuration is written from the values entered above and uses owner-only permissions.</p>
</main></body></html>
