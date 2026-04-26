<?php
require __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/lib/gogo.php';

/*
 * 7gogo public talk API notes
 *
 * Endpoint shape:
 *   https://api.7gogo.jp/web/v2/talks/{talkId}/posts?talkId={talkId}&targetId={id}&direction=NEXT
 *
 * Response shape:
 *   {
 *     "data": [
 *       {
 *         "post": {
 *           "postId": 1316,
 *           "postType": 4,
 *           "body": [ ... mixed body blocks ... ],
 *           "time": 1433770676,
 *           ...
 *         },
 *         "user": {
 *           "userId": "...",
 *           "name": "西野七瀬(乃木坂46)",
 *           ...
 *         }
 *       }
 *     ],
 *     "error": null
 *   }
 *
 * Archiving rules used by this project:
 * - `storage/raw/{member}` stores raw API pages without reshaping the JSON.
 * - Full mode walks forward from the configured startId until the API returns no posts.
 * - The next targetId is based on the largest postId returned, so deleted/hidden posts do not truncate the crawl.
 * - Incremental mode starts from the current local max postId + 1.
 * - Resume only affects full mode: it continues from the saved next targetId after the last unfinished checkpoint.
 */

$options = gogo_parse_cli_options($argv);
$member = gogo_resolve_member($options);
$talk = gogo_build_talk_config($member, $options);

$mode = isset($options['mode']) ? (string) $options['mode'] : 'full';
if (!in_array($mode, ['full', 'incremental'], true)) {
  throw new RuntimeException('Unsupported mode: ' . $mode . '. Use --mode=full or --mode=incremental.');
}

$pageSize = gogo_cli_int($options, 'page-size', gogo_cli_int($options, 'per-page', (int) $talk['pageSize']));
$delayMs = gogo_cli_int($options, 'delay-ms', (int) $talk['delayMs']);
$maxPages = array_key_exists('max-pages', $options) ? max(0, (int) $options['max-pages']) : null;
$dryRun = gogo_cli_bool($options, 'dry-run', false);
$overwrite = gogo_cli_bool($options, 'overwrite', false);
$resume = gogo_cli_bool($options, 'resume', false);

gogo_ensure_dir(gogo_data_dir($member));
gogo_ensure_dir(gogo_error_dir());
gogo_ensure_dir(dirname(gogo_state_path($member)));

$state = gogo_load_state($member);
$startTarget = gogo_cli_int($options, 'start', 0);

if ($mode === 'incremental') {
  // Incremental sync deliberately uses the current max post id as the next target probe.
  // The later localization/manifest step is responsible for keeping post ids unique if the API overlaps.
  if ($startTarget <= 0) {
    $maxPostId = gogo_find_max_post_id($member);
    $startTarget = $maxPostId > 0 ? $maxPostId + 1 : (int) $talk['startId'];
  }
} elseif ($startTarget <= 0) {
  // Resume only makes sense for the forward full-history crawl.
  if ($resume && isset($state['mode'], $state['lastTargetId']) && $state['mode'] === 'full' && empty($state['completed'])) {
    $startTarget = isset($state['nextTargetId']) ? (int) $state['nextTargetId'] : (int) $state['lastTargetId'] + 1;
  } else {
    $startTarget = (int) $talk['startId'];
  }
}

$targetId = $startTarget;
$pagesFetched = 0;
$pagesWritten = 0;
$pagesSkipped = 0;
$totalPosts = 0;
$maxPostIdSeen = 0;
$logFile = gogo_error_dir() . DIRECTORY_SEPARATOR . $member . '-data.log';

gogo_log('Starting data fetch: member=' . $member . ', mode=' . $mode . ', start=' . $targetId . ', pageSize=' . $pageSize . ', dryRun=' . ($dryRun ? 'yes' : 'no'));

$curl = gogo_create_curl($talk);

while (true) {
  if ($maxPages !== null && $pagesFetched >= $maxPages) {
    gogo_log('Stopped after --max-pages=' . $maxPages . '.');
    break;
  }

  $existingFile = (!$overwrite && !$dryRun) ? gogo_find_json_file_starting_at(gogo_data_dir($member), $member, $targetId) : null;
  $fileName = null;
  $data = null;
  $requested = false;

  if ($existingFile !== null) {
    // A valid existing raw page is treated as immutable archive data.
    $fileName = basename($existingFile);
    $data = gogo_read_json_file($existingFile);
    $pagesSkipped++;
    gogo_log('Existing raw file is valid, skipped request: storage/raw/' . $member . '/' . $fileName);
  } else {
    $url = gogo_build_posts_url($talk, $targetId);
    gogo_log('Requesting targetId=' . $targetId . ' url=' . $url);
    $result = gogo_request_posts($curl, $url, $talk, $logFile);
    $requested = true;

    if (!$result['ok']) {
      gogo_save_state($member, [
        'member' => $member,
        'mode' => $mode,
        'lastTargetId' => $targetId,
        'completed' => false,
        'lastError' => $result['error'],
      ]);
      exit(1);
    }

    $data = $result['data'];
    $postCount = is_array($data) ? count($data) : 0;
    $range = gogo_post_id_range(is_array($data) ? $data : []);
    $fileEnd = $range['max'] !== null ? (int) $range['max'] : $targetId;
    $fileName = $member . '_' . $targetId . '-' . $fileEnd . '.json';
    $filePath = gogo_data_dir($member) . DIRECTORY_SEPARATOR . $fileName;

    if ($dryRun) {
      gogo_log('Dry run: received ' . count($data) . ' posts for ' . $fileName . ', not writing files.');
    } elseif ($postCount === 0) {
      gogo_log('No raw file written for empty response at targetId=' . $targetId . '.');
    } else {
      gogo_write_json_file($filePath, $data);
      $pagesWritten++;
      gogo_log('Saved raw file: storage/raw/' . $member . '/' . $fileName . ' (' . count($data) . ' posts)');
    }
  }

  $pagesFetched++;
  $postCount = is_array($data) ? count($data) : 0;
  $totalPosts += $postCount;
  $range = gogo_post_id_range(is_array($data) ? $data : []);
  $nextTargetId = $range['max'] !== null ? (int) $range['max'] + 1 : $targetId;
  $completed = $postCount === 0 || $nextTargetId <= $targetId;

  if ($range['max'] !== null) {
    $maxPostIdSeen = max($maxPostIdSeen, (int) $range['max']);
  }

  if (!$dryRun) {
    // State tracks the crawl frontier only; it is intentionally smaller than manifest.json.
    gogo_save_state($member, [
      'member' => $member,
      'mode' => $mode,
      'lastTargetId' => $targetId,
      'lastRawFile' => $fileName,
      'maxPostId' => max((int) ($state['maxPostId'] ?? 0), $maxPostIdSeen),
      'nextTargetId' => $nextTargetId,
      'completed' => $completed,
      'pageSize' => $pageSize,
    ]);
  }

  if ($postCount === 0) {
    if ($mode === 'incremental') {
      gogo_log('No new posts returned for incremental targetId=' . $targetId . '.');
    } else {
      gogo_log('Reached the end: no posts returned for targetId=' . $targetId . '.');
    }
    break;
  }

  if ($nextTargetId <= $targetId) {
    gogo_log('Stopped because the API did not advance past targetId=' . $targetId . '.', 'warn');
    break;
  }

  if ($postCount < $pageSize) {
    gogo_log('Short response: received ' . $postCount . ' posts; continuing at targetId=' . $nextTargetId . '.');
  }

  $targetId = $nextTargetId;

  if ($requested) {
    gogo_sleep_ms($delayMs);
  }
}

$curl->close();

gogo_log('Done. pages=' . $pagesFetched . ', written=' . $pagesWritten . ', skipped=' . $pagesSkipped . ', postsSeen=' . $totalPosts . ', maxPostId=' . $maxPostIdSeen);
