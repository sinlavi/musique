<?php
if (PHP_SAPI !== 'cli') {
  http_response_code(404);
  exit;
}

$target = getenv('UPDATE_INSTALL_DIR') ?: dirname(__DIR__);
$branch = getenv('UPDATE_BRANCH') ?: 'main';
$target = realpath($target);
$validBranch = preg_match('/^[A-Za-z0-9][A-Za-z0-9._\/-]{0,99}$/', $branch) &&
  !preg_match('~(?:^|/)\.{1,2}(?:/|$)~', $branch);
if ($target === false || !is_dir($target) || !is_dir($target . '/api') || !$validBranch) {
  fwrite(STDERR, "Invalid update target or branch.\n");
  exit(2);
}
if (!extension_loaded('curl') || !class_exists(PharData::class)) {
  fwrite(STDERR, "The updater requires PHP cURL and PharData.\n");
  exit(2);
}

$lock = fopen(sys_get_temp_dir() . '/mman-update-' . hash('sha256', $target) . '.lock', 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
  fwrite(STDERR, "Another mman update is already running.\n");
  exit(1);
}
$work = sys_get_temp_dir() . '/mman-update-' . bin2hex(random_bytes(8));
if (!mkdir($work, 0700, true)) {
  fwrite(STDERR, "Could not create update workspace.\n");
  exit(1);
}

function update_fail($message, $work) {
  fwrite(STDERR, $message . "\n");
  remove_update_workspace($work);
  exit(1);
}

function remove_update_workspace($path) {
  if (!is_dir($path) || is_link($path)) {
    @unlink($path);
    return;
  }
  foreach (new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS) as $entry) {
    if ($entry->isDir() && !$entry->isLink()) remove_update_workspace($entry->getPathname());
    else @unlink($entry->getPathname());
  }
  @rmdir($path);
}

try {
  $archivePath = $work . '/source.tar.gz';
  $archive = fopen($archivePath, 'wb');
  if ($archive === false) update_fail('Could not create update archive.', $work);
  $url = 'https://codeload.github.com/sinlavi/musique/tar.gz/refs/heads/' . rawurlencode($branch);
  $curl = curl_init($url);
  curl_setopt_array($curl, [
    CURLOPT_FILE => $archive,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS => 3,
    CURLOPT_CONNECTTIMEOUT => 15,
    CURLOPT_TIMEOUT => 180,
    CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
    CURLOPT_USERAGENT => 'mman-host-updater/1.0',
  ]);
  $downloaded = curl_exec($curl);
  $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
  $error = curl_error($curl);
  curl_close($curl);
  fclose($archive);
  if (!$downloaded || $status !== 200 || filesize($archivePath) < 1024) {
    update_fail("Could not download GitHub release (HTTP $status): $error", $work);
  }

  $tar = new PharData($archivePath);
  $tar->extractTo($work . '/release', null, true);
  $roots = glob($work . '/release/musique-*', GLOB_ONLYDIR);
  if (!$roots || count($roots) !== 1) update_fail('Unexpected GitHub archive layout.', $work);
  $sourceRoot = $roots[0];
  $copied = 0;
  $iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($sourceRoot, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
  );
  foreach ($iterator as $entry) {
    $relative = substr($entry->getPathname(), strlen($sourceRoot) + 1);
    $relative = str_replace(DIRECTORY_SEPARATOR, '/', $relative);
    $segments = explode('/', $relative);
    if (in_array('..', $segments, true) || str_starts_with($relative, '/')) {
      update_fail('Unsafe path found in release archive.', $work);
    }
    if ($relative === 'api/config.php' || $relative === 'api/data' ||
        str_starts_with($relative, 'api/data/') || $relative === '.git' ||
        str_starts_with($relative, '.git/') || $relative === 'bot/.env' ||
        $relative === 'bot/cookies.txt' || str_starts_with($relative, 'bot/worker-state/') ||
        $relative === 'bot/.worker-state' || str_starts_with($relative, 'bot/.worker-state/') ||
        $relative === 'install.php') {
      continue;
    }
    if ($entry->isLink()) update_fail("Release contains an unexpected symlink: $relative", $work);
    $destination = $target . '/' . $relative;
    if ($entry->isDir()) {
      $directory = $target;
      foreach ($segments as $segment) {
        $directory .= '/' . $segment;
        if (is_link($directory)) update_fail("Destination contains an unexpected symlink: $relative", $work);
        if (!is_dir($directory) && !mkdir($directory, 0755) && !is_dir($directory)) {
          update_fail("Could not create directory: $relative", $work);
        }
      }
      continue;
    }
    $directory = $target;
    foreach (array_slice($segments, 0, -1) as $segment) {
      $directory .= '/' . $segment;
      if (is_link($directory)) update_fail("Destination contains an unexpected symlink: $relative", $work);
      if (!is_dir($directory) && !mkdir($directory, 0755) && !is_dir($directory)) {
        update_fail("Could not create destination directory for: $relative", $work);
      }
    }
    $temporary = $destination . '.mman-update-' . bin2hex(random_bytes(4));
    if (!copy($entry->getPathname(), $temporary) || !rename($temporary, $destination)) {
      @unlink($temporary);
      update_fail("Could not install file: $relative", $work);
    }
    $copied++;
  }
  echo "Updated $copied files in $target from sinlavi/musique:$branch\n";
} catch (Throwable $e) {
  update_fail('Update failed: ' . $e->getMessage(), $work);
}

remove_update_workspace($work);
flock($lock, LOCK_UN);
fclose($lock);
