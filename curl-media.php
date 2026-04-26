<?php
require __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/lib/gogo.php';

function gogo_is_media_field($key, array $node = [])
{
  return in_array($key, [
    'thumbnailUrl',
    'coverImageUrl',
    'coverImageThumbnailUrl',
    'image',
    'thumbnail',
    'video',
    'movie',
    'videoUrl',
    'movieUrl',
    'videoUrlHq',
    'videoUrlNormal',
    'movieUrlHq',
    'movieUrlNormal',
    'videoThumbnail',
    'movieThumbnail',
    'videoThumbnailUrl',
    'movieThumbnailUrl',
  ], true);
}

function gogo_media_kind_for_field($key)
{
  $normalized = strtolower($key);

  if (strpos($normalized, 'video') !== false || strpos($normalized, 'movie') !== false) {
    if (strpos($normalized, 'thumbnail') !== false) {
      return 'thumbnail';
    }

    return 'video';
  }

  if ($normalized === 'thumbnail' || strpos($normalized, 'thumbnail') !== false) {
    return 'thumbnail';
  }

  return 'image';
}

function gogo_media_relative_dir($kind)
{
  if ($kind === 'thumbnail') {
    return 'thumbnails';
  }

  if ($kind === 'video') {
    return 'videos';
  }

  return 'images';
}

function gogo_media_target_dir($member, $kind)
{
  if ($kind === 'thumbnail') {
    return gogo_thumbnail_dir($member);
  }

  if ($kind === 'video') {
    return gogo_video_dir($member);
  }

  return gogo_image_dir($member);
}

function gogo_add_media_task($url, $field, $member, array &$tasks, array &$stats)
{
  $filename = gogo_local_filename_from_url($url);

  if ($filename === null) {
    return $url;
  }

  $kind = gogo_media_kind_for_field($field);
  $key = $kind . ':' . $url;

  if (!isset($tasks[$key])) {
    $tasks[$key] = [
      'url' => $url,
      'field' => $field,
      'kind' => $kind,
      'filename' => $filename,
      'targetPath' => gogo_media_target_dir($member, $kind) . DIRECTORY_SEPARATOR . $filename,
      'relativePath' => $member . '/' . gogo_media_relative_dir($kind) . '/' . $filename,
    ];
    $stats['mediaTasks']++;
    $stats[$kind . 'Tasks']++;
  }

  return $filename;
}

function gogo_localize_node($node, $member, array &$tasks, array &$stats)
{
  if (!is_array($node)) {
    return $node;
  }

  if (gogo_is_list_array($node)) {
    $items = [];
    foreach ($node as $item) {
      $items[] = gogo_localize_node($item, $member, $tasks, $stats);
    }
    return $items;
  }

  $localized = [];

  foreach ($node as $key => $value) {
    if (is_string($value) && gogo_is_media_field($key, $node) && filter_var($value, FILTER_VALIDATE_URL)) {
      $localized[$key] = gogo_add_media_task($value, $key, $member, $tasks, $stats);
      continue;
    }

    if (is_array($value)) {
      $localized[$key] = gogo_localize_node($value, $member, $tasks, $stats);
      continue;
    }

    $localized[$key] = $value;
  }

  return $localized;
}

function gogo_collect_body_types($node, array &$bodyTypes)
{
  if (!is_array($node)) {
    return;
  }

  if (isset($node['bodyType'])) {
    $bodyType = (string) $node['bodyType'];
    $bodyTypes[$bodyType] = ($bodyTypes[$bodyType] ?? 0) + 1;
  }

  foreach ($node as $value) {
    if (is_array($value)) {
      gogo_collect_body_types($value, $bodyTypes);
    }
  }
}

function gogo_pick_profile(array $posts, array $currentProfile)
{
  // Pick the best visible author metadata for the viewer sidebar without modifying the post payload.
  foreach ($posts as $entry) {
    if (!isset($entry['user']) || !is_array($entry['user'])) {
      continue;
    }

    $user = $entry['user'];
    $name = isset($user['name']) ? (string) $user['name'] : '';

    if ($name === '' || $name === '削除されたユーザー') {
      continue;
    }

    $currentProfile['name'] = $name;

    foreach (['description', 'thumbnailUrl', 'coverImageUrl', 'coverImageThumbnailUrl'] as $field) {
      if (isset($user[$field]) && $user[$field] !== null && $user[$field] !== '') {
        $currentProfile[$field] = $user[$field];
      }
    }
  }

  return $currentProfile;
}

function gogo_first_non_empty_value(...$values)
{
  foreach ($values as $value) {
    if ($value !== null && $value !== '') {
      return $value;
    }
  }

  return null;
}

function gogo_media_video_value(array $item)
{
  foreach (['movieUrlHq', 'videoUrlHq', 'movieUrlNormal', 'videoUrlNormal', 'video', 'movie', 'videoUrl', 'movieUrl'] as $field) {
    if (isset($item[$field]) && is_string($item[$field]) && $item[$field] !== '') {
      return $item[$field];
    }
  }

  return null;
}

function gogo_media_thumbnail_value(array $item)
{
  foreach (['thumbnailUrl', 'thumbnail', 'movieThumbnail', 'videoThumbnail', 'movieThumbnailUrl', 'videoThumbnailUrl'] as $field) {
    if (isset($item[$field]) && is_string($item[$field]) && $item[$field] !== '') {
      return $item[$field];
    }
  }

  return null;
}

function gogo_build_media_index_item(array $item, array $post, array $user, $position)
{
  $video = gogo_media_video_value($item);
  $image = isset($item['image']) && is_string($item['image']) && $item['image'] !== ''
    ? $item['image']
    : null;
  $thumbnail = gogo_media_thumbnail_value($item);

  if ($video === null && $image === null && $thumbnail === null) {
    return null;
  }

  return [
    'postId' => isset($post['postId']) ? (int) $post['postId'] : null,
    'postType' => isset($post['postType']) ? (int) $post['postType'] : null,
    'time' => isset($post['time']) ? (int) $post['time'] : null,
    'position' => is_int($position) ? $position : null,
    'type' => $video !== null ? 'video' : 'image',
    'image' => $image,
    'thumbnail' => $thumbnail,
    'video' => $video,
    'width' => isset($item['width']) ? (int) $item['width'] : null,
    'height' => isset($item['height']) ? (int) $item['height'] : null,
    'userName' => isset($user['name']) && is_string($user['name']) && $user['name'] !== ''
      ? $user['name']
      : null,
  ];
}

function gogo_collect_post_media(array $body, array $post, array $user, array &$mediaEntries)
{
  foreach ($body as $position => $item) {
    if (!is_array($item)) {
      continue;
    }

    $mediaEntry = gogo_build_media_index_item($item, $post, $user, $position);
    if ($mediaEntry !== null) {
      $mediaEntries[] = $mediaEntry;
    }

    if (isset($item['post']['body']) && is_array($item['post']['body'])) {
      gogo_collect_post_media($item['post']['body'], $post, $user, $mediaEntries);
    }
  }
}

function gogo_collect_media_index(array $posts, array &$mediaEntries)
{
  foreach ($posts as $entry) {
    if (!isset($entry['post']) || !is_array($entry['post'])) {
      continue;
    }

    $post = $entry['post'];
    $body = isset($post['body']) && is_array($post['body']) ? $post['body'] : [];
    if ($body === []) {
      continue;
    }

    $user = isset($entry['user']) && is_array($entry['user']) ? $entry['user'] : [];
    gogo_collect_post_media($body, $post, $user, $mediaEntries);
  }
}

function gogo_media_index_stats(array $mediaEntries)
{
  $stats = [
    'count' => count($mediaEntries),
    'images' => 0,
    'videos' => 0,
  ];

  foreach ($mediaEntries as $item) {
    if (($item['type'] ?? null) === 'video') {
      $stats['videos']++;
      continue;
    }

    $stats['images']++;
  }

  return $stats;
}

function gogo_archive_index_item(array $manifest, array $profileDocument, $member)
{
  $profile = isset($profileDocument['profile']) && is_array($profileDocument['profile'])
    ? $profileDocument['profile']
    : (isset($manifest['profile']) && is_array($manifest['profile']) ? $manifest['profile'] : []);

  return [
    'member' => $member,
    'talkId' => $manifest['talkId'] ?? $member,
    'displayName' => gogo_first_non_empty_value($profileDocument['displayName'] ?? null, $manifest['displayName'] ?? null, $profile['name'] ?? null, $member),
    'description' => gogo_first_non_empty_value($profileDocument['description'] ?? null, $manifest['description'] ?? null, $profile['description'] ?? null, ''),
    'watchCount' => $profileDocument['watchCount'] ?? ($manifest['watchCount'] ?? null),
    'generatedAt' => gogo_first_non_empty_value($profileDocument['generatedAt'] ?? null, $manifest['generatedAt'] ?? null, null),
    'profile' => [
      'name' => $profile['name'] ?? null,
      'description' => $profile['description'] ?? null,
      'thumbnailUrl' => $profile['thumbnailUrl'] ?? null,
      'coverImageUrl' => $profile['coverImageUrl'] ?? null,
      'coverImageThumbnailUrl' => $profile['coverImageThumbnailUrl'] ?? null,
    ],
    'paths' => [
      'manifest' => 'storage/local/' . $member . '/manifest.json',
      'profile' => $manifest['paths']['profile'] ?? ('storage/local/' . $member . '/profile.json'),
      'media' => $manifest['paths']['media'] ?? ('storage/local/' . $member . '/media.json'),
      'images' => $manifest['paths']['images'] ?? ('storage/media/' . $member . '/images'),
      'thumbnails' => $manifest['paths']['thumbnails'] ?? ('storage/media/' . $member . '/thumbnails'),
      'videos' => $manifest['paths']['videos'] ?? ('storage/media/' . $member . '/videos'),
    ],
    'totals' => [
      'posts' => $manifest['totals']['posts'] ?? 0,
      'minPostId' => $manifest['totals']['minPostId'] ?? null,
      'maxPostId' => $manifest['totals']['maxPostId'] ?? null,
    ],
  ];
}

function gogo_write_archive_index($member)
{
  $localRoot = dirname(gogo_local_data_dir($member));
  $members = [];

  foreach (glob($localRoot . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) ?: [] as $memberDir) {
    $memberKey = basename($memberDir);
    $manifestPath = $memberDir . DIRECTORY_SEPARATOR . 'manifest.json';
    if (!is_file($manifestPath)) {
      continue;
    }

    $manifest = gogo_read_json_file($manifestPath);
    $profilePath = $memberDir . DIRECTORY_SEPARATOR . 'profile.json';
    $profileDocument = is_file($profilePath) ? gogo_read_json_file($profilePath) : [];
    $members[] = gogo_archive_index_item($manifest, is_array($profileDocument) ? $profileDocument : [], $memberKey);
  }

  usort($members, function ($left, $right) {
    $displayCompare = strcasecmp((string) ($left['displayName'] ?? ''), (string) ($right['displayName'] ?? ''));
    if ($displayCompare !== 0) {
      return $displayCompare;
    }

    return strcmp((string) ($left['member'] ?? ''), (string) ($right['member'] ?? ''));
  });

  gogo_write_json_file($localRoot . DIRECTORY_SEPARATOR . 'index.json', [
    'version' => 1,
    'generatedAt' => gogo_timestamp(),
    'members' => $members,
  ]);
}

function gogo_media_file_valid($path, $kind)
{
  if (!is_file($path) || filesize($path) === 0) {
    return false;
  }

  if ($kind === 'image' || $kind === 'thumbnail') {
    return @getimagesize($path) !== false;
  }

  return true;
}

function gogo_download_media_tasks(array $tasks, array $talk, $member, array $options)
{
  $dryRun = gogo_cli_bool($options, 'dry-run', false);
  $noDownload = gogo_cli_bool($options, 'download', true) === false;
  $overwrite = gogo_cli_bool($options, 'overwrite-media', false);
  $delayMs = gogo_cli_int($options, 'media-delay-ms', gogo_cli_int($options, 'delay-ms', (int) $talk['delayMs']));
  $logFile = gogo_error_dir() . DIRECTORY_SEPARATOR . $member . '-media.log';
  $summary = [
    'downloaded' => 0,
    'skipped' => 0,
    'failed' => 0,
    'dryRun' => 0,
  ];

  if ($noDownload) {
    gogo_log('Media download disabled by --no-download.');
    return $summary;
  }

  $curl = gogo_create_curl($talk);

  foreach ($tasks as $task) {
    gogo_ensure_dir(dirname($task['targetPath']));

    if (!$overwrite && gogo_media_file_valid($task['targetPath'], $task['kind'])) {
      // Local archive files are content-addressed by URL-derived name, so a valid existing file can be reused.
      $summary['skipped']++;
      continue;
    }

    if ($dryRun) {
      gogo_log('Dry run: would download ' . $task['url'] . ' -> ' . $task['relativePath']);
      $summary['dryRun']++;
      continue;
    }

    $tmpPath = $task['targetPath'] . '.tmp';
    if (is_file($tmpPath)) {
      unlink($tmpPath);
    }

    $curl->download($task['url'], $tmpPath);
    $status = isset($curl->httpStatusCode) ? (int) $curl->httpStatusCode : 0;

    if ($curl->error || $status < 200 || $status >= 300 || !gogo_media_file_valid($tmpPath, $task['kind'])) {
      $message = 'Failed to download ' . $task['url'] . ' to ' . $task['relativePath'];
      if ($curl->error) {
        $message .= ': ' . $curl->errorMessage;
      } elseif ($status < 200 || $status >= 300) {
        $message .= ': HTTP status ' . $status;
      } else {
        $message .= ': downloaded file failed validation';
      }

      if (is_file($tmpPath)) {
        unlink($tmpPath);
      }

      gogo_log($message, 'error');
      gogo_log_file($logFile, $message);
      $summary['failed']++;
      gogo_sleep_ms($delayMs);
      continue;
    }

    rename($tmpPath, $task['targetPath']);
    $summary['downloaded']++;
    gogo_log('Downloaded media: ' . $task['relativePath']);
    gogo_sleep_ms($delayMs);
  }

  $curl->close();

  return $summary;
}

$options = gogo_parse_cli_options($argv);
$member = gogo_resolve_member($options);
$talk = gogo_build_talk_config($member, $options);
$maxFiles = array_key_exists('max-files', $options) ? max(0, (int) $options['max-files']) : null;

gogo_ensure_dir(gogo_local_data_dir($member));
gogo_ensure_dir(gogo_image_dir($member));
gogo_ensure_dir(gogo_thumbnail_dir($member));
gogo_ensure_dir(gogo_video_dir($member));
gogo_ensure_dir(gogo_error_dir());

$rawFiles = gogo_sorted_json_files(gogo_data_dir($member), $member);
if (count($rawFiles) === 0) {
  gogo_log('No raw JSON files found in storage/raw/' . $member, 'error');
  exit(1);
}

$tasks = [];
$bodyTypes = [];
$stats = [
  'mediaTasks' => 0,
  'imageTasks' => 0,
  'thumbnailTasks' => 0,
  'videoTasks' => 0,
];
$dataFiles = [];
$profile = [
  'name' => $talk['displayName'] ?? $member,
  'description' => $talk['description'] ?? '',
  'thumbnailUrl' => null,
  'coverImageUrl' => null,
  'coverImageThumbnailUrl' => null,
];
$mediaEntries = [];
$totals = [
  'posts' => 0,
  'minPostId' => null,
  'maxPostId' => null,
];

gogo_log('Starting media localization for member=' . $member . ', files=' . count($rawFiles));

foreach ($rawFiles as $index => $rawFile) {
  if ($maxFiles !== null && $index >= $maxFiles) {
    break;
  }

  $range = gogo_extract_file_range($rawFile, $member) ?: ['start' => null, 'end' => null];
  $rawData = gogo_read_json_file($rawFile);
  $localData = gogo_localize_node($rawData, $member, $tasks, $stats);

  // localData must keep the same post count and object shape as rawData.
  gogo_collect_body_types($localData, $bodyTypes);
  $profile = gogo_pick_profile($localData, $profile);
  gogo_collect_media_index(is_array($localData) ? $localData : [], $mediaEntries);

  $postRange = gogo_post_id_range(is_array($localData) ? $localData : []);
  $localFileName = basename($rawFile);
  gogo_write_json_file(gogo_local_data_dir($member) . DIRECTORY_SEPARATOR . $localFileName, $localData);

  $postCount = is_array($localData) ? count($localData) : 0;
  $totals['posts'] += $postCount;

  if ($postRange['min'] !== null) {
    $totals['minPostId'] = $totals['minPostId'] === null ? $postRange['min'] : min($totals['minPostId'], $postRange['min']);
  }

  if ($postRange['max'] !== null) {
    $totals['maxPostId'] = $totals['maxPostId'] === null ? $postRange['max'] : max($totals['maxPostId'], $postRange['max']);
  }

  $dataFiles[] = [
    'file' => $localFileName,
    'start' => $range['start'],
    'end' => $range['end'],
    'postCount' => $postCount,
    'minPostId' => $postRange['min'],
    'maxPostId' => $postRange['max'],
  ];

  gogo_log('Wrote local JSON: storage/local/' . $member . '/' . $localFileName . ' (' . $postCount . ' posts)');
}

$downloadSummary = gogo_download_media_tasks($tasks, $talk, $member, $options);
$displayName = $talk['displayName'] !== $member ? $talk['displayName'] : ($profile['name'] ?: $member);
$description = $talk['description'] !== '' ? $talk['description'] : ($profile['description'] ?: '');
$generatedAt = gogo_timestamp();
usort($mediaEntries, function ($left, $right) {
  $timeCompare = (int) ($right['time'] ?? 0) <=> (int) ($left['time'] ?? 0);
  if ($timeCompare !== 0) {
    return $timeCompare;
  }

  return (int) ($right['postId'] ?? 0) <=> (int) ($left['postId'] ?? 0);
});

$profileDocument = [
  'version' => 1,
  'member' => $member,
  'talkId' => $talk['talkId'],
  'displayName' => $displayName,
  'description' => $description,
  'watchCount' => $talk['watchCount'] ?? null,
  'generatedAt' => $generatedAt,
  'profile' => $profile,
];

$manifest = [
  'version' => 1,
  'member' => $member,
  'talkId' => $talk['talkId'],
  'displayName' => $displayName,
  'description' => $description,
  'watchCount' => $talk['watchCount'] ?? null,
  'generatedAt' => $generatedAt,
  'paths' => [
    'data' => 'storage/local/' . $member,
    'profile' => 'storage/local/' . $member . '/profile.json',
    'media' => 'storage/local/' . $member . '/media.json',
    'images' => 'storage/media/' . $member . '/images',
    'thumbnails' => 'storage/media/' . $member . '/thumbnails',
    'videos' => 'storage/media/' . $member . '/videos',
  ],
  'profile' => $profile,
  'dataFiles' => $dataFiles,
  'bodyTypes' => $bodyTypes,
  'media' => [
    'tasks' => array_values($tasks),
    'stats' => $stats,
    'downloads' => $downloadSummary,
  ],
  'totals' => $totals,
];

$mediaDocument = [
  'version' => 1,
  'member' => $member,
  'talkId' => $talk['talkId'],
  'displayName' => $displayName,
  'generatedAt' => $generatedAt,
  'items' => $mediaEntries,
  'stats' => gogo_media_index_stats($mediaEntries),
];

gogo_write_json_file(gogo_local_data_dir($member) . DIRECTORY_SEPARATOR . 'profile.json', $profileDocument);
gogo_write_json_file(gogo_local_data_dir($member) . DIRECTORY_SEPARATOR . 'manifest.json', $manifest);
gogo_write_json_file(gogo_local_data_dir($member) . DIRECTORY_SEPARATOR . 'media.json', $mediaDocument);
gogo_write_archive_index($member);

gogo_log('Done. posts=' . $totals['posts'] . ', mediaTasks=' . $stats['mediaTasks'] . ', downloaded=' . $downloadSummary['downloaded'] . ', skipped=' . $downloadSummary['skipped'] . ', failed=' . $downloadSummary['failed']);
