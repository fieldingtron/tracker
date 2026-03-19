<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/bot.php';
require_once __DIR__ . '/lib/events.php';

if (!app_is_installed()) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'App not installed. Run setup.php';
    exit;
}

try {
    $config = app_config();
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Config error';
    exit;
}

$target = trim((string)($_GET['to'] ?? $_GET['url'] ?? ''));
$label = substr(trim((string)($_GET['name'] ?? $_GET['label'] ?? 'redirect')), 0, 255);
$pageUrl = substr(trim((string)($_SERVER['HTTP_REFERER'] ?? '')), 0, 2000);
$referrer = $pageUrl;
$clientTs = substr(trim((string)($_GET['ts'] ?? '')), 0, 64);
$createdAt = time();
$userAgent = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 1000);
$botClass = estimate_bot_class($userAgent, $_SERVER);
$siteHost = normalize_site_host((string)($_GET['site'] ?? ''));

if ($siteHost === '') {
    $siteHost = normalize_site_host($pageUrl);
}
if ($siteHost === '') {
    $siteHost = normalize_site_host((string)($config['primary_site'] ?? ''));
}

$allowedSites = site_allowlist($config);
$refererHost = normalize_site_host($pageUrl);

if ($target === '' || filter_var($target, FILTER_VALIDATE_URL) === false) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Invalid redirect target';
    exit;
}

if ($siteHost === '' || !in_array($siteHost, $allowedSites, true)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Site not allowed';
    exit;
}

if ($refererHost !== '' && !in_array($refererHost, $allowedSites, true)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Referer not allowed';
    exit;
}

try {
    insert_event(db(), [
        'event_type' => 'redirect',
        'event_name' => $label,
        'event_value' => substr($target, 0, 2000),
        'page_url' => $pageUrl !== '' ? $pageUrl : 'redirect://' . $siteHost,
        'referrer' => $referrer,
        'site' => $siteHost,
        'user_agent' => $userAgent,
        'bot_class' => $botClass,
        'created_at' => $createdAt,
        'client_ts' => $clientTs,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Failed to record redirect';
    exit;
}

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Location: ' . $target, true, 302);
exit;
