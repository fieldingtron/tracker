<?php

declare(strict_types=1);

/**
 * Umami batch exporter.
 *
 * Reads unexported human events from the SQLite database and POSTs them to
 * a self-hosted Umami instance via its /api/send event ingestion endpoint.
 * Marks rows as exported only after a successful API response.
 *
 * Usage:
 *   php scripts/umami-export.php              # normal run
 *   php scripts/umami-export.php --dry-run    # print payloads, do not POST or mark exported
 *
 * Cron (hourly — change schedule only to adjust frequency):
 *   0 * * * * /usr/local/bin/php /path/to/scripts/umami-export.php >> /path/to/data/umami-export.log 2>&1
 *
 * Exit codes:
 *   0 — all events exported (or no events to export)
 *   1 — one or more events failed
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

const BATCH_SIZE     = 50;   // rows per HTTP flush + DB update
const MAX_PER_RUN    = 500;  // total rows processed per cron invocation
const CURL_TIMEOUT_S = 5;    // seconds per individual POST

$isDryRun = in_array('--dry-run', $argv ?? [], true);

require_once __DIR__ . '/../lib/bootstrap.php';

// ── Config ────────────────────────────────────────────────────────────────────

$config = app_config();

$umamiUrl       = rtrim((string)($config['umami_url'] ?? ''), '/');
$umamiWebsiteId = (string)($config['umami_website_id'] ?? '');
$umamiApiKey    = (string)($config['umami_api_key'] ?? '');

if ($umamiUrl === '' || $umamiWebsiteId === '') {
    log_line('ERROR: umami_url and umami_website_id must be set in config.php');
    exit(1);
}

$endpoint = $umamiUrl . '/api/send';

// ── DB ────────────────────────────────────────────────────────────────────────

$pdo = db();

// Auto-apply migration if exported_at column is absent.
ensure_exported_at_column($pdo);

// ── Fetch rows ────────────────────────────────────────────────────────────────

$stmt = $pdo->prepare(
    'SELECT id, event_type, event_name, event_value, page_url, referrer,
            site, user_agent, created_at
     FROM events
     WHERE exported_at IS NULL AND bot_class = \'human\'
     ORDER BY id ASC
     LIMIT :limit'
);
$stmt->bindValue(':limit', MAX_PER_RUN, PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

if ($rows === []) {
    log_line('No unexported events. Done.');
    exit(0);
}

log_line(sprintf('Found %d unexported human event(s). Exporting to %s%s', count($rows), $endpoint, $isDryRun ? ' [DRY RUN]' : ''));

// ── Process in batches ────────────────────────────────────────────────────────

$totalExported = 0;
$totalFailed   = 0;
$batches       = array_chunk($rows, BATCH_SIZE);

foreach ($batches as $batch) {
    $successIds = [];
    $failedIds  = [];

    foreach ($batch as $row) {
        $payload = build_payload($row, $umamiWebsiteId);

        if ($isDryRun) {
            echo '[dry-run] id=' . $row['id'] . ' ' . json_encode($payload, JSON_UNESCAPED_SLASHES) . "\n";
            $successIds[] = (int)$row['id'];
            continue;
        }

        [$ok, $httpStatus, $responseBody] = post_to_umami($endpoint, $payload, (string)$row['user_agent'], $umamiApiKey);

        if ($ok) {
            $successIds[] = (int)$row['id'];
        } else {
            $failedIds[] = (int)$row['id'];
            log_line(sprintf(
                'FAIL id=%d status=%s body=%s',
                $row['id'],
                $httpStatus,
                substr($responseBody, 0, 500)
            ));
        }
    }

    // Mark successes in one transaction.
    if ($successIds !== [] && !$isDryRun) {
        try {
            mark_exported($pdo, $successIds);
        } catch (Throwable $e) {
            log_line('ERROR marking exported: ' . $e->getMessage() . ' — ids: ' . implode(',', $successIds));
            // Move successes to failed so they retry next run.
            $failedIds   = array_merge($failedIds, $successIds);
            $successIds  = [];
        }
    }

    $totalExported += count($successIds);
    $totalFailed   += count($failedIds);

    log_line(sprintf('batch exported=%d failed=%d', count($successIds), count($failedIds)));
}

log_line(sprintf('Run complete. total_exported=%d total_failed=%d', $totalExported, $totalFailed));
exit($totalFailed > 0 ? 1 : 0);

// ── Helpers ───────────────────────────────────────────────────────────────────

function build_payload(array $row, string $websiteId): array
{
    // Parse page_url to extract path + query string only.
    $parsed = parse_url((string)$row['page_url']);
    $urlPath = ($parsed['path'] ?? '/');
    if (isset($parsed['query']) && $parsed['query'] !== '') {
        $urlPath .= '?' . $parsed['query'];
    }
    if (isset($parsed['fragment']) && $parsed['fragment'] !== '') {
        $urlPath .= '#' . $parsed['fragment'];
    }

    $inner = [
        'website'  => $websiteId,
        'hostname' => (string)$row['site'],
        'url'      => $urlPath,
    ];

    // Referrer (skip empty strings).
    if (isset($row['referrer']) && $row['referrer'] !== '') {
        $inner['referrer'] = (string)$row['referrer'];
    }

    // Event name: pageviews have no name; click/custom use event_name field.
    $eventType = (string)$row['event_type'];
    if ($eventType !== 'pageview') {
        $inner['name'] = ($row['event_name'] !== null && $row['event_name'] !== '')
            ? (string)$row['event_name']
            : $eventType;
    }

    // Custom data for event_value.
    if (isset($row['event_value']) && $row['event_value'] !== '') {
        $inner['data'] = ['value' => (string)$row['event_value']];
    }

    return ['type' => 'event', 'payload' => $inner];
}

/**
 * @return array{bool, string, string}  [success, httpStatus, responseBody]
 */
function post_to_umami(string $endpoint, array $payload, string $userAgent, string $apiKey): array
{
    $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    $headers = [
        'Content-Type: application/json',
    ];

    // Send original user agent so Umami can parse browser/OS/device.
    if ($userAgent !== '') {
        $headers[] = 'User-Agent: ' . $userAgent;
    }

    if ($apiKey !== '') {
        $headers[] = 'Authorization: Bearer ' . $apiKey;
    }

    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => CURL_TIMEOUT_S,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
    ]);

    $response   = curl_exec($ch);
    $httpStatus = (string)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError  = curl_error($ch);
    curl_close($ch);

    if ($curlError !== '') {
        return [false, 'curl_error', $curlError];
    }

    $ok = $httpStatus >= '200' && $httpStatus < '300';
    return [$ok, $httpStatus, (string)$response];
}

function mark_exported(PDO $pdo, array $ids): void
{
    $now         = time();
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $pdo->beginTransaction();
    $stmt = $pdo->prepare("UPDATE events SET exported_at = ? WHERE id IN ($placeholders)");
    $stmt->execute(array_merge([$now], $ids));
    $pdo->commit();
}

function ensure_exported_at_column(PDO $pdo): void
{
    $info = $pdo->query("PRAGMA table_info(events)")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($info as $col) {
        if ($col['name'] === 'exported_at') {
            return;
        }
    }
    $pdo->exec('ALTER TABLE events ADD COLUMN exported_at INTEGER DEFAULT NULL');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_events_exported_at ON events (exported_at)');
    log_line('[auto-migrate] exported_at column added.');
}

function log_line(string $msg): void
{
    $ts = gmdate('Y-m-d\TH:i:s\Z');
    echo "[{$ts}] {$msg}\n";
}
