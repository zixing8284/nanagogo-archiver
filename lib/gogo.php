<?php

use Curl\Curl;

function gogo_root_path(...$segments)
{
  $path = dirname(__DIR__);

  foreach ($segments as $segment) {
    if ($segment === null || $segment === '') {
      continue;
    }

    $path .= DIRECTORY_SEPARATOR . trim((string) $segment, DIRECTORY_SEPARATOR);
  }

  return $path;
}

function gogo_storage_path(...$segments)
{
  return gogo_root_path('storage', ...$segments);
}

function gogo_timestamp()
{
  return date('c');
}

function gogo_ensure_dir($path)
{
  if (!is_dir($path)) {
    mkdir($path, 0755, true);
  }
}

function gogo_log($message, $level = 'info')
{
  echo '[' . gogo_timestamp() . '] [' . strtoupper($level) . '] ' . $message . PHP_EOL;
}

function gogo_log_file($filePath, $message, $level = 'error')
{
  gogo_ensure_dir(dirname($filePath));
  file_put_contents(
    $filePath,
    '[' . gogo_timestamp() . '] [' . strtoupper($level) . '] ' . $message . PHP_EOL,
    FILE_APPEND | LOCK_EX
  );
}

function gogo_read_json_file($filePath)
{
  if (!is_file($filePath)) {
    throw new RuntimeException('JSON file not found: ' . $filePath);
  }

  $json = file_get_contents($filePath);
  $data = json_decode($json, true);

  if (json_last_error() !== JSON_ERROR_NONE) {
    throw new RuntimeException('Invalid JSON in ' . $filePath . ': ' . json_last_error_msg());
  }

  return $data;
}

function gogo_write_json_file($filePath, $data)
{
  gogo_ensure_dir(dirname($filePath));

  $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

  if ($json === false) {
    throw new RuntimeException('Failed to encode JSON for ' . $filePath . ': ' . json_last_error_msg());
  }

  $tmpPath = $filePath . '.tmp';
  file_put_contents($tmpPath, $json . PHP_EOL, LOCK_EX);
  rename($tmpPath, $filePath);
}

function gogo_parse_cli_options(array $argv)
{
  $options = [];

  foreach (array_slice($argv, 1) as $arg) {
    if (strpos($arg, '--') !== 0) {
      continue;
    }

    $arg = substr($arg, 2);

    if (strpos($arg, '=') !== false) {
      [$key, $value] = explode('=', $arg, 2);
      $options[$key] = $value;
      continue;
    }

    if (strpos($arg, 'no-') === 0) {
      $options[substr($arg, 3)] = false;
      continue;
    }

    $options[$arg] = true;
  }

  return $options;
}

function gogo_cli_bool(array $options, $key, $default = false)
{
  if (!array_key_exists($key, $options)) {
    return $default;
  }

  $value = $options[$key];

  if (is_bool($value)) {
    return $value;
  }

  $normalized = strtolower((string) $value);
  return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
}

function gogo_cli_int(array $options, $key, $default)
{
  if (!array_key_exists($key, $options) || $options[$key] === '') {
    return $default;
  }

  return (int) $options[$key];
}

function gogo_default_talk_config()
{
  return [
    'baseApiUrl' => 'https://api.7gogo.jp/web/v2/talks',
    'direction' => 'NEXT',
    'pageSize' => 100,
    'startId' => 1,
    'delayMs' => 1200,
    'retries' => 3,
    'retryDelayMs' => 1500,
    'userAgent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36 7gogo-local-archive/1.0',
  ];
}

function gogo_resolve_member(array $options)
{
  if (isset($options['member']) && $options['member'] !== '') {
    return (string) $options['member'];
  }

  throw new RuntimeException('--member is required. Example: php curl-data.php --member=nishino-nanase');
}

function gogo_build_talk_config($member, array $options = [])
{
  $talk = gogo_default_talk_config();
  $talk['member'] = $member;
  $talk['talkId'] = isset($options['talk-id']) && $options['talk-id'] !== '' ? (string) $options['talk-id'] : $member;
  $talk['displayName'] = isset($options['display-name']) && $options['display-name'] !== '' ? (string) $options['display-name'] : $member;
  $talk['description'] = isset($options['description']) ? (string) $options['description'] : '';
  $talk['watchCount'] = isset($options['watch-count']) && $options['watch-count'] !== '' ? (int) $options['watch-count'] : null;

  foreach (
    [
      'baseApiUrl' => 'base-api-url',
      'direction' => 'direction',
      'userAgent' => 'user-agent',
    ] as $configKey => $optionKey
  ) {
    if (isset($options[$optionKey]) && $options[$optionKey] !== '') {
      $talk[$configKey] = (string) $options[$optionKey];
    }
  }

  foreach (
    [
      'pageSize' => 'page-size',
      'startId' => 'start-id',
      'delayMs' => 'delay-ms',
      'retries' => 'retries',
      'retryDelayMs' => 'retry-delay-ms',
    ] as $configKey => $optionKey
  ) {
    if (array_key_exists($optionKey, $options) && $options[$optionKey] !== '') {
      $talk[$configKey] = (int) $options[$optionKey];
    }
  }

  if (array_key_exists('per-page', $options) && $options['per-page'] !== '') {
    $talk['pageSize'] = (int) $options['per-page'];
  }

  $talk['direction'] = strtoupper($talk['direction']);

  return $talk;
}

function gogo_data_dir($member)
{
  return gogo_storage_path('raw', $member);
}

function gogo_local_data_dir($member)
{
  return gogo_storage_path('local', $member);
}

function gogo_image_dir($member)
{
  return gogo_storage_path('media', $member, 'images');
}

function gogo_thumbnail_dir($member)
{
  return gogo_storage_path('media', $member, 'thumbnails');
}

function gogo_video_dir($member)
{
  return gogo_storage_path('media', $member, 'videos');
}

function gogo_error_dir()
{
  return gogo_storage_path('logs');
}

function gogo_state_path($member)
{
  return gogo_storage_path('state', $member . '.json');
}

function gogo_load_state($member)
{
  $path = gogo_state_path($member);

  if (!is_file($path)) {
    return [];
  }

  return gogo_read_json_file($path);
}

function gogo_save_state($member, array $state)
{
  $state['updatedAt'] = gogo_timestamp();
  gogo_write_json_file(gogo_state_path($member), $state);
}

function gogo_build_posts_url(array $talk, $targetId = null)
{
  $baseApiUrl = rtrim($talk['baseApiUrl'], '/');
  $talkId = $talk['talkId'];
  $query = [
    'talkId' => $talkId,
    'direction' => $talk['direction'],
  ];

  if ($targetId !== null) {
    $query['targetId'] = $targetId;
  }

  return $baseApiUrl . '/' . rawurlencode($talkId) . '/posts?' . http_build_query($query);
}

function gogo_create_curl(array $talk)
{
  $curl = new Curl();
  $curl->setUserAgent($talk['userAgent']);
  $curl->setHeader('Accept', 'application/json, text/plain, */*');
  $curl->setHeader('Accept-Language', 'ja,en-US;q=0.9,en;q=0.8');
  $curl->setHeader('Referer', 'https://7gogo.jp/' . $talk['talkId']);
  $curl->setOpt(CURLOPT_CONNECTTIMEOUT, 20);
  $curl->setOpt(CURLOPT_TIMEOUT, 90);
  $curl->setOpt(CURLOPT_FOLLOWLOCATION, true);

  return $curl;
}

function gogo_response_to_array($response)
{
  if (is_array($response)) {
    return $response;
  }

  if (is_object($response)) {
    return json_decode(json_encode($response), true);
  }

  if (is_string($response)) {
    return json_decode($response, true);
  }

  return null;
}

function gogo_request_posts(Curl $curl, $url, array $talk, $logFile)
{
  $retries = (int) $talk['retries'];
  $retryDelayMs = (int) $talk['retryDelayMs'];

  for ($attempt = 0; $attempt <= $retries; $attempt++) {
    $curl->get($url);
    $status = isset($curl->httpStatusCode) ? (int) $curl->httpStatusCode : 0;
    $retryable = false;
    $error = null;

    if ($curl->error) {
      $error = 'Curl error: ' . $curl->errorMessage;
      $retryable = true;
    } elseif ($status < 200 || $status >= 300) {
      $error = 'HTTP status ' . $status;
      $retryable = $status === 429 || $status >= 500;
    } else {
      $response = gogo_response_to_array($curl->response);

      if (!is_array($response)) {
        $error = 'Invalid JSON response';
        $retryable = true;
      } elseif (array_key_exists('error', $response) && $response['error'] !== null) {
        $error = 'API error: ' . json_encode($response['error'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $retryable = false;
      } elseif (!isset($response['data']) || !is_array($response['data'])) {
        $error = 'API response does not contain a data array';
        $retryable = false;
      } else {
        return [
          'ok' => true,
          'status' => $status,
          'data' => $response['data'],
          'error' => null,
        ];
      }
    }

    $message = $error . ' when requesting ' . $url . ' (attempt ' . ($attempt + 1) . '/' . ($retries + 1) . ')';
    gogo_log($message, $retryable && $attempt < $retries ? 'warn' : 'error');
    gogo_log_file($logFile, $message);

    if (!$retryable || $attempt >= $retries) {
      return [
        'ok' => false,
        'status' => $status,
        'data' => [],
        'error' => $error,
      ];
    }

    usleep(($retryDelayMs * ($attempt + 1)) * 1000);
  }

  return [
    'ok' => false,
    'status' => 0,
    'data' => [],
    'error' => 'Unknown request failure',
  ];
}

function gogo_sleep_ms($milliseconds)
{
  if ($milliseconds > 0) {
    usleep($milliseconds * 1000);
  }
}

function gogo_extract_file_range($filePath, $member)
{
  $name = basename($filePath);
  $pattern = '/^' . preg_quote($member, '/') . '_(\d+)-(\d+)\.json$/';

  if (!preg_match($pattern, $name, $matches)) {
    return null;
  }

  return [
    'start' => (int) $matches[1],
    'end' => (int) $matches[2],
  ];
}

function gogo_sorted_json_files($dir, $member)
{
  if (!is_dir($dir)) {
    return [];
  }

  $files = glob($dir . DIRECTORY_SEPARATOR . $member . '_*.json');

  usort($files, function ($left, $right) use ($member) {
    $leftRange = gogo_extract_file_range($left, $member);
    $rightRange = gogo_extract_file_range($right, $member);
    $leftStart = $leftRange ? $leftRange['start'] : PHP_INT_MAX;
    $rightStart = $rightRange ? $rightRange['start'] : PHP_INT_MAX;

    if ($leftStart === $rightStart) {
      return strcmp($left, $right);
    }

    return $leftStart <=> $rightStart;
  });

  return $files;
}

function gogo_find_json_file_starting_at($dir, $member, $start)
{
  foreach (gogo_sorted_json_files($dir, $member) as $file) {
    $range = gogo_extract_file_range($file, $member);

    if ($range !== null && $range['start'] === (int) $start) {
      return $file;
    }
  }

  return null;
}

function gogo_is_list_array(array $value)
{
  if ($value === []) {
    return true;
  }

  return array_keys($value) === range(0, count($value) - 1);
}

function gogo_post_id_range(array $posts)
{
  $min = null;
  $max = null;

  foreach ($posts as $entry) {
    if (!isset($entry['post']['postId'])) {
      continue;
    }

    $postId = (int) $entry['post']['postId'];
    $min = $min === null ? $postId : min($min, $postId);
    $max = $max === null ? $postId : max($max, $postId);
  }

  return [
    'min' => $min,
    'max' => $max,
  ];
}

function gogo_find_max_post_id($member)
{
  $maxPostId = 0;
  $manifestPath = gogo_local_data_dir($member) . DIRECTORY_SEPARATOR . 'manifest.json';

  if (is_file($manifestPath)) {
    $manifest = gogo_read_json_file($manifestPath);
    if (isset($manifest['totals']['maxPostId'])) {
      $maxPostId = max($maxPostId, (int) $manifest['totals']['maxPostId']);
    }
  }

  $files = gogo_sorted_json_files(gogo_data_dir($member), $member);

  foreach ($files as $file) {
    $data = gogo_read_json_file($file);
    $range = gogo_post_id_range(is_array($data) ? $data : []);

    if ($range['max'] !== null) {
      $maxPostId = max($maxPostId, (int) $range['max']);
    }
  }

  return $maxPostId;
}

function gogo_local_filename_from_url($url)
{
  if (!is_string($url) || filter_var($url, FILTER_VALIDATE_URL) === false) {
    return null;
  }

  $parts = parse_url($url);
  if (!isset($parts['path']) || $parts['path'] === '') {
    return null;
  }

  $pathParts = explode('/', trim($parts['path'], '/'));
  if (isset($pathParts[0]) && $pathParts[0] === 'appimg_images') {
    array_shift($pathParts);
  }

  if (count($pathParts) === 0) {
    return null;
  }

  $filename = rawurldecode(implode('_', $pathParts));
  $filename = preg_replace('/[^A-Za-z0-9._-]+/', '_', $filename);

  return $filename ?: null;
}
