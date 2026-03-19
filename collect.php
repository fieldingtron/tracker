<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/bot.php';
require_once __DIR__ . '/lib/events.php';

header('Content-Type: application/json');

if (!app_is_installed()) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'App not installed. Run setup.php']);
    exit;
}

try {
    $config = app_config();
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Config error']);
    exit;
}

function collect_forbidden(string $message): void
{
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => $message]);
    exit;
}

function collect_bad_request(string $message): void
{
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $message]);
    exit;
}

$allowedOrigins = origin_allowlist($config);
$origin = normalize_origin((string)($_SERVER['HTTP_ORIGIN'] ?? ''));

if ($origin !== '') {
    if ($allowedOrigins !== [] && !in_array($origin, $allowedOrigins, true)) {
        collect_forbidden('Origin not allowed');
    }
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
}
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$raw = file_get_contents('php://input');
$payload = json_decode($raw ?: '', true);
if (!is_array($payload)) {
    collect_bad_request('Invalid JSON');
}

$eventType = (string)($payload['event_type'] ?? '');
if (!in_array($eventType, allowed_event_types(), true)) {
    collect_bad_request('Invalid event_type');
}

$pageUrl = substr((string)($payload['page_url'] ?? ''), 0, 2000);
if ($pageUrl === '') {
    collect_bad_request('Missing page_url');
}

$siteValue = (string)($payload['site'] ?? '');
$siteHost = normalize_site_host($siteValue);
if ($siteHost === '') {
    $siteHost = normalize_site_host((string)($config['primary_site'] ?? ''));
}

$allowedSites = site_allowlist($config);
if ($siteHost === '' || !in_array($siteHost, $allowedSites, true)) {
    collect_forbidden('Site not allowed');
}

if ($origin === '') {
    $refererHost = normalize_site_host((string)($_SERVER['HTTP_REFERER'] ?? ''));
    if ($refererHost !== '' && !in_array($refererHost, $allowedSites, true)) {
        collect_forbidden('Referer not allowed');
    }
}

$referrer = substr((string)($payload['referrer'] ?? ''), 0, 2000);
$userAgent = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 1000);
$eventName = substr((string)($payload['event_name'] ?? ''), 0, 255);
$eventValue = substr((string)($payload['event_value'] ?? ''), 0, 2000);
$clientTs = substr((string)($payload['ts'] ?? ''), 0, 64);
$createdAt = time();
$botClass = estimate_bot_class($userAgent, $_SERVER);

try {
    insert_event(db(), [
        'event_type' => $eventType,
        'event_name' => $eventName,
        'event_value' => $eventValue,
        'page_url' => $pageUrl,
        'referrer' => $referrer,
        'site' => $siteHost,
        'user_agent' => $userAgent,
        'bot_class' => $botClass,
        'created_at' => $createdAt,
        'client_ts' => $clientTs,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Failed to record event']);
    exit;
}

http_response_code(204);
