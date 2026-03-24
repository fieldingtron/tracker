<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/bootstrap.php';

require_admin_auth();

$config = app_config();
$siteLabel = (string)($config['primary_site'] ?? 'Site');
$pdo = db();
$now = time();
$todayStart = strtotime('today') ?: ($now - 86400);
$range = (int)($_GET['range'] ?? 7);
if (!in_array($range, [7, 30], true)) {
    $range = 7;
}
$rangeStart = $now - ($range * 86400);

function scalar_query(PDO $pdo, string $sql, array $params = []): int
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int)$stmt->fetchColumn();
}

function grouped_count(array $rows, string $keyName, string $countName = 'c'): array
{
    $map = [];
    foreach ($rows as $row) {
        $map[(string)$row[$keyName]] = (int)$row[$countName];
    }

    return $map;
}

function short_label(string $url): string
{
    $trimmed = trim($url);
    if ($trimmed === '') {
        return 'Direct';
    }

    $parts = parse_url($trimmed);
    if (!is_array($parts)) {
        return strlen($trimmed) > 58 ? substr($trimmed, 0, 55) . '...' : $trimmed;
    }

    $host = (string)($parts['host'] ?? '');
    $path = (string)($parts['path'] ?? '/');
    $query = isset($parts['query']) ? '?' . $parts['query'] : '';
    $value = $host !== '' ? $host . $path . $query : $trimmed;

    return strlen($value) > 58 ? substr($value, 0, 55) . '...' : $value;
}

function fill_daily_series(array $rows, int $range, int $now): array
{
    $series = [];
    for ($i = $range - 1; $i >= 0; $i--) {
        $day = date('Y-m-d', strtotime("-{$i} days", $now));
        $series[$day] = 0;
    }

    foreach ($rows as $row) {
        $day = (string)$row['day'];
        if (array_key_exists($day, $series)) {
            $series[$day] = (int)$row['c'];
        }
    }

    $output = [];
    foreach ($series as $day => $count) {
        $output[] = [
            'day' => $day,
            'label' => date('M j', strtotime($day)),
            'count' => $count,
        ];
    }

    return $output;
}

function chart_points(array $series, int $width = 760, int $height = 250, int $pad = 24): array
{
    $count = count($series);
    if ($count === 0) {
        return [];
    }

    $max = max(array_column($series, 'count'));
    $max = max($max, 1);
    $stepX = $count === 1 ? 0 : ($width - ($pad * 2)) / ($count - 1);
    $plotHeight = $height - ($pad * 2);
    $points = [];

    foreach ($series as $index => $row) {
        $x = $pad + ($index * $stepX);
        $y = $height - $pad - (($row['count'] / $max) * $plotHeight);
        $points[] = ['x' => round($x, 2), 'y' => round($y, 2), 'value' => (int)$row['count'], 'label' => (string)$row['label']];
    }

    return $points;
}

function svg_line_path(array $points): string
{
    if ($points === []) {
        return '';
    }

    $path = 'M ' . $points[0]['x'] . ' ' . $points[0]['y'];
    for ($i = 1, $len = count($points); $i < $len; $i++) {
        $path .= ' L ' . $points[$i]['x'] . ' ' . $points[$i]['y'];
    }

    return $path;
}

function svg_area_path(array $points, int $height = 250, int $pad = 24): string
{
    if ($points === []) {
        return '';
    }

    $path = svg_line_path($points);
    $last = $points[count($points) - 1];
    $first = $points[0];

    return $path . ' L ' . $last['x'] . ' ' . ($height - $pad) . ' L ' . $first['x'] . ' ' . ($height - $pad) . ' Z';
}

function percent_width(int $value, int $max): float
{
    if ($max <= 0) {
        return 0.0;
    }

    return round(($value / $max) * 100, 2);
}

$todayTotal = scalar_query($pdo, 'SELECT COUNT(*) FROM events WHERE created_at >= :start', [':start' => $todayStart]);
$windowTotal = scalar_query($pdo, 'SELECT COUNT(*) FROM events WHERE created_at >= :start', [':start' => $rangeStart]);
$windowPageviews = scalar_query($pdo, 'SELECT COUNT(*) FROM events WHERE created_at >= :start AND event_type = :type', [':start' => $rangeStart, ':type' => 'pageview']);
$affiliateCount = scalar_query($pdo, 'SELECT COUNT(*) FROM events WHERE created_at >= :start AND event_type = :type', [':start' => $rangeStart, ':type' => 'affiliate_click']);
$redirectCount = scalar_query($pdo, 'SELECT COUNT(*) FROM events WHERE created_at >= :start AND event_type = :type', [':start' => $rangeStart, ':type' => 'redirect']);

$botMixStmt = $pdo->prepare(
    'SELECT bot_class, COUNT(*) AS c
     FROM events
     WHERE created_at >= :start
     GROUP BY bot_class'
);
$botMixStmt->execute([':start' => $rangeStart]);
$botMix = grouped_count($botMixStmt->fetchAll(), 'bot_class');
$humanCount = $botMix['human'] ?? 0;
$botCount = $botMix['bot'] ?? 0;
$unknownCount = $botMix['unknown'] ?? 0;

$eventCountsStmt = $pdo->prepare(
    'SELECT event_type, COUNT(*) AS c
     FROM events
     WHERE created_at >= :start
     GROUP BY event_type
     ORDER BY c DESC'
);
$eventCountsStmt->execute([':start' => $rangeStart]);
$eventCounts = $eventCountsStmt->fetchAll();
$eventCountMax = 0;
foreach ($eventCounts as $row) {
    $eventCountMax = max($eventCountMax, (int)$row['c']);
}

$topPagesStmt = $pdo->prepare(
    'SELECT page_url, COUNT(*) AS c
     FROM events
     WHERE created_at >= :start
       AND event_type = :type
     GROUP BY page_url
     ORDER BY c DESC
     LIMIT 10'
);
$topPagesStmt->execute([':start' => $rangeStart, ':type' => 'pageview']);
$topPages = $topPagesStmt->fetchAll();
$topPagesMax = 0;
foreach ($topPages as $row) {
    $topPagesMax = max($topPagesMax, (int)$row['c']);
}

$topRefStmt = $pdo->prepare(
    'SELECT referrer, COUNT(*) AS c
     FROM events
     WHERE created_at >= :start
       AND event_type = :type
       AND referrer IS NOT NULL
       AND referrer != ""
     GROUP BY referrer
     ORDER BY c DESC
     LIMIT 10'
);
$topRefStmt->execute([':start' => $rangeStart, ':type' => 'pageview']);
$topReferrers = $topRefStmt->fetchAll();
$topRefMax = 0;
foreach ($topReferrers as $row) {
    $topRefMax = max($topRefMax, (int)$row['c']);
}

$affiliateStmt = $pdo->prepare(
    'SELECT event_name, event_value, COUNT(*) AS c
     FROM events
     WHERE created_at >= :start
       AND event_type = :type
     GROUP BY event_name, event_value
     ORDER BY c DESC
     LIMIT 10'
);
$affiliateStmt->execute([':start' => $rangeStart, ':type' => 'affiliate_click']);
$affiliateLinks = $affiliateStmt->fetchAll();
$affiliateMax = 0;
foreach ($affiliateLinks as $row) {
    $affiliateMax = max($affiliateMax, (int)$row['c']);
}

$redirectStmt = $pdo->prepare(
    'SELECT event_name, event_value, COUNT(*) AS c
     FROM events
     WHERE created_at >= :start
       AND event_type = :type
     GROUP BY event_name, event_value
     ORDER BY c DESC
     LIMIT 10'
);
$redirectStmt->execute([':start' => $rangeStart, ':type' => 'redirect']);
$redirects = $redirectStmt->fetchAll();
$redirectMax = 0;
foreach ($redirects as $row) {
    $redirectMax = max($redirectMax, (int)$row['c']);
}

$dailyStmt = $pdo->prepare(
    "SELECT date(created_at, 'unixepoch', 'localtime') AS day, COUNT(*) AS c
     FROM events
     WHERE created_at >= :start
       AND event_type = :type
     GROUP BY day
     ORDER BY day ASC"
);
$dailyStmt->execute([':start' => $rangeStart, ':type' => 'pageview']);
$daily = fill_daily_series($dailyStmt->fetchAll(), $range, $now);
$chartPoints = chart_points($daily);
$chartPath = svg_line_path($chartPoints);
$chartAreaPath = svg_area_path($chartPoints);
$chartMax = 0;
foreach ($daily as $row) {
    $chartMax = max($chartMax, (int)$row['count']);
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Tiny Analytics</title>
    <style>
        :root {
            --paper: #f2efe8;
            --panel: rgba(255, 251, 245, 0.86);
            --panel-strong: #fffef9;
            --ink: #1f1d1a;
            --muted: #6f655c;
            --line: rgba(94, 79, 64, 0.16);
            --accent: #ff6f3c;
            --accent-soft: rgba(255, 111, 60, 0.14);
            --accent-2: #156e63;
            --accent-3: #3459d1;
            --shadow: 0 18px 50px rgba(61, 43, 24, 0.08);
        }
        @media (prefers-color-scheme: dark) {
            :root {
                --bg: #111318;
                --panel: #1c1f2b;
                --text: #e2e8f0;
                --muted: #8896ab;
                --line: #2d3748;
                --accent: #4d8cff;
            }
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            background:
                radial-gradient(circle at top left, rgba(255, 186, 122, 0.26), transparent 28%),
                radial-gradient(circle at top right, rgba(82, 196, 167, 0.18), transparent 26%),
                linear-gradient(180deg, #f8f3ea 0%, #efe8dc 100%);
            color: var(--ink);
            font-family: Georgia, "Iowan Old Style", "Palatino Linotype", serif;
        }
        .wrap {
            max-width: 1180px;
            margin: 0 auto;
            padding: 24px 18px 32px;
        }
        .top {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 16px;
            margin-bottom: 18px;
        }
        .eyebrow {
            margin: 0 0 6px;
            color: var(--accent-2);
            font-size: 12px;
            letter-spacing: 0.18em;
            text-transform: uppercase;
            font-family: "Trebuchet MS", "Gill Sans", sans-serif;
        }
        h1 {
            margin: 0;
            font-size: clamp(2rem, 3.8vw, 3.3rem);
            line-height: 0.95;
            font-weight: 700;
        }
        .sub {
            margin-top: 8px;
            color: var(--muted);
            font-size: 14px;
            font-family: "Trebuchet MS", "Gill Sans", sans-serif;
        }
        .actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            align-items: center;
            justify-content: flex-end;
        }
        .chip,
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 40px;
            padding: 0 14px;
            border-radius: 999px;
            text-decoration: none;
            border: 1px solid var(--line);
            color: var(--ink);
            background: rgba(255, 255, 255, 0.7);
            font-family: "Trebuchet MS", "Gill Sans", sans-serif;
            font-size: 13px;
        }
        .chip.active {
            background: var(--ink);
            color: #fff;
            border-color: var(--ink);
        }
        .hero {
            display: grid;
            grid-template-columns: 2.1fr 1fr;
            gap: 14px;
            margin-bottom: 14px;
        }
        .panel {
            position: relative;
            overflow: hidden;
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 24px;
            box-shadow: var(--shadow);
            backdrop-filter: blur(12px);
        }
        .panel::before {
            content: "";
            position: absolute;
            inset: auto -10% 60% auto;
            width: 160px;
            height: 160px;
            background: radial-gradient(circle, rgba(255, 111, 60, 0.22), transparent 68%);
            pointer-events: none;
        }
        .chart-panel {
            padding: 18px 18px 14px;
        }
        .chart-header,
        .split-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 14px;
        }
        .section-kicker {
            color: var(--muted);
            text-transform: uppercase;
            letter-spacing: 0.14em;
            font-size: 11px;
            font-family: "Trebuchet MS", "Gill Sans", sans-serif;
        }
        .section-title {
            margin: 4px 0 0;
            font-size: 24px;
            line-height: 1.05;
        }
        .big-number {
            text-align: right;
            font-family: "Trebuchet MS", "Gill Sans", sans-serif;
        }
        .big-number strong {
            display: block;
            font-size: 34px;
            line-height: 1;
        }
        .big-number span {
            color: var(--muted);
            font-size: 12px;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }
        .chart-wrap {
            background: linear-gradient(180deg, rgba(255,255,255,0.45), rgba(255,255,255,0.12));
            border: 1px solid rgba(94, 79, 64, 0.08);
            border-radius: 18px;
            padding: 12px 12px 10px;
        }
        .axis {
            display: flex;
            justify-content: space-between;
            gap: 8px;
            margin-top: 8px;
            color: var(--muted);
            font-size: 11px;
            font-family: "Trebuchet MS", "Gill Sans", sans-serif;
        }
        .stats-panel {
            padding: 18px;
            display: grid;
            grid-template-columns: 1fr;
            gap: 10px;
        }
        .kpi {
            padding: 14px;
            border-radius: 18px;
            background: var(--panel-strong);
            border: 1px solid rgba(94, 79, 64, 0.08);
        }
        .kpi-label {
            color: var(--muted);
            text-transform: uppercase;
            letter-spacing: 0.12em;
            font-size: 11px;
            font-family: "Trebuchet MS", "Gill Sans", sans-serif;
        }
        .kpi-value {
            margin-top: 4px;
            font-size: 32px;
            line-height: 1;
        }
        .kpi-note {
            margin-top: 6px;
            color: var(--muted);
            font-size: 13px;
            font-family: "Trebuchet MS", "Gill Sans", sans-serif;
        }
        .grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 14px;
        }
        .panel-body {
            padding: 18px;
        }
        .list {
            display: grid;
            gap: 10px;
        }
        .row {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            gap: 10px;
            align-items: center;
        }
        .row-title {
            font-family: "Trebuchet MS", "Gill Sans", sans-serif;
            font-size: 14px;
        }
        .row-meta {
            color: var(--muted);
            font-size: 12px;
            font-family: "Trebuchet MS", "Gill Sans", sans-serif;
            margin-top: 3px;
            word-break: break-all;
        }
        .row-value {
            font-family: "Trebuchet MS", "Gill Sans", sans-serif;
            font-size: 13px;
            color: var(--muted);
        }
        .meter {
            width: 100%;
            height: 8px;
            border-radius: 999px;
            overflow: hidden;
            background: rgba(52, 89, 209, 0.08);
            margin-top: 7px;
        }
        .meter > span {
            display: block;
            height: 100%;
            border-radius: inherit;
            background: linear-gradient(90deg, var(--accent), #ffb066);
        }
        .meter.green > span {
            background: linear-gradient(90deg, var(--accent-2), #52c4a7);
        }
        .meter.blue > span {
            background: linear-gradient(90deg, var(--accent-3), #7aa1ff);
        }
        .bot-stack {
            display: grid;
            gap: 8px;
        }
        .stack-row {
            display: grid;
            grid-template-columns: 90px 1fr 52px;
            gap: 10px;
            align-items: center;
            font-family: "Trebuchet MS", "Gill Sans", sans-serif;
            font-size: 13px;
        }
        .stack-track {
            height: 10px;
            border-radius: 999px;
            overflow: hidden;
            background: rgba(31, 29, 26, 0.08);
        }
        .stack-fill {
            height: 100%;
            border-radius: inherit;
        }
        .stack-fill.human { background: linear-gradient(90deg, var(--accent-2), #7de3ba); }
        .stack-fill.bot { background: linear-gradient(90deg, var(--accent), #ffb066); }
        .stack-fill.unknown { background: linear-gradient(90deg, var(--accent-3), #9ab4ff); }
        .empty {
            color: var(--muted);
            font-family: "Trebuchet MS", "Gill Sans", sans-serif;
            font-size: 13px;
        }
        @media (max-width: 980px) {
            .hero,
            .grid {
                grid-template-columns: 1fr;
            }
        }
        @media (max-width: 720px) {
            .top,
            .chart-header,
            .split-header {
                flex-direction: column;
            }
            .actions {
                justify-content: flex-start;
            }
        }
    </style>
</head>
<body>
<div class="wrap">
    <div class="top">
        <div>
            <p class="eyebrow">Tiny Analytics</p>
            <h1><?= htmlspecialchars($siteLabel, ENT_QUOTES, 'UTF-8') ?></h1>
            <div class="sub">Server-rendered analytics with affiliate and redirect tracking.</div>
        </div>
        <div class="actions">
            <a class="chip<?= $range === 7 ? ' active' : '' ?>" href="/admin/index.php?range=7">7 days</a>
            <a class="chip<?= $range === 30 ? ' active' : '' ?>" href="/admin/index.php?range=30">30 days</a>
            <a class="btn" href="/admin/logout.php">Sign out</a>
        </div>
    </div>

    <div class="hero">
        <section class="panel chart-panel">
            <div class="chart-header">
                <div>
                    <div class="section-kicker">Traffic Trend</div>
                    <h2 class="section-title">Pageviews over the last <?= $range ?> days</h2>
                </div>
                <div class="big-number">
                    <strong><?= number_format($windowPageviews) ?></strong>
                    <span>Pageviews</span>
                </div>
            </div>
            <div class="chart-wrap">
                <svg viewBox="0 0 760 250" width="100%" height="250" preserveAspectRatio="none" aria-label="Pageviews line chart">
                    <defs>
                        <linearGradient id="trafficFill" x1="0" y1="0" x2="0" y2="1">
                            <stop offset="0%" stop-color="#ff6f3c" stop-opacity="0.32"/>
                            <stop offset="100%" stop-color="#ff6f3c" stop-opacity="0"/>
                        </linearGradient>
                    </defs>
                    <line x1="24" y1="226" x2="736" y2="226" stroke="rgba(94, 79, 64, 0.16)" stroke-width="1"/>
                    <line x1="24" y1="125" x2="736" y2="125" stroke="rgba(94, 79, 64, 0.10)" stroke-width="1" stroke-dasharray="4 7"/>
                    <line x1="24" y1="24" x2="736" y2="24" stroke="rgba(94, 79, 64, 0.08)" stroke-width="1" stroke-dasharray="4 7"/>
                    <?php if ($chartPath !== ''): ?>
                        <path d="<?= htmlspecialchars($chartAreaPath, ENT_QUOTES, 'UTF-8') ?>" fill="url(#trafficFill)"></path>
                        <path d="<?= htmlspecialchars($chartPath, ENT_QUOTES, 'UTF-8') ?>" fill="none" stroke="#ff6f3c" stroke-width="4" stroke-linecap="round" stroke-linejoin="round"></path>
                        <?php foreach ($chartPoints as $point): ?>
                            <circle cx="<?= $point['x'] ?>" cy="<?= $point['y'] ?>" r="4" fill="#fff8f2" stroke="#ff6f3c" stroke-width="3">
                                <title><?= htmlspecialchars($point['label'] . ': ' . $point['value'], ENT_QUOTES, 'UTF-8') ?></title>
                            </circle>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </svg>
                <div class="axis">
                    <span><?= htmlspecialchars($daily[0]['label'] ?? '', ENT_QUOTES, 'UTF-8') ?></span>
                    <span>Peak <?= number_format($chartMax) ?></span>
                    <span><?= htmlspecialchars($daily[count($daily) - 1]['label'] ?? '', ENT_QUOTES, 'UTF-8') ?></span>
                </div>
            </div>
        </section>

        <aside class="panel stats-panel">
            <div class="kpi">
                <div class="kpi-label">Events Today</div>
                <div class="kpi-value"><?= number_format($todayTotal) ?></div>
                <div class="kpi-note">Everything recorded since local midnight.</div>
            </div>
            <div class="kpi">
                <div class="kpi-label">All Events</div>
                <div class="kpi-value"><?= number_format($windowTotal) ?></div>
                <div class="kpi-note">Pageviews, clicks, affiliate hits, redirects, and custom events.</div>
            </div>
            <div class="kpi">
                <div class="kpi-label">Affiliate Clicks</div>
                <div class="kpi-value"><?= number_format($affiliateCount) ?></div>
                <div class="kpi-note">Direct clicks tracked with <code>data-analytics-affiliate</code>.</div>
            </div>
            <div class="kpi">
                <div class="kpi-label">Redirect Hits</div>
                <div class="kpi-value"><?= number_format($redirectCount) ?></div>
                <div class="kpi-note">Server-side clicks routed through <code>redirect.php</code>.</div>
            </div>
        </aside>
    </div>

    <div class="grid">
        <section class="panel">
            <div class="panel-body">
                <div class="split-header">
                    <div>
                        <div class="section-kicker">Breakdown</div>
                        <h2 class="section-title">Event mix</h2>
                    </div>
                </div>
                <?php if ($eventCounts === []): ?>
                    <div class="empty">No events yet.</div>
                <?php else: ?>
                    <div class="list">
                        <?php foreach ($eventCounts as $row): ?>
                            <?php $count = (int)$row['c']; ?>
                            <div>
                                <div class="row">
                                    <div class="row-title"><?= htmlspecialchars((string)$row['event_type'], ENT_QUOTES, 'UTF-8') ?></div>
                                    <div class="row-value"><?= number_format($count) ?></div>
                                </div>
                                <div class="meter blue"><span style="width: <?= percent_width($count, $eventCountMax) ?>%"></span></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </section>

        <section class="panel">
            <div class="panel-body">
                <div class="split-header">
                    <div>
                        <div class="section-kicker">Quality</div>
                        <h2 class="section-title">Human vs bot</h2>
                    </div>
                </div>
                <?php $botMax = max($humanCount, $botCount, $unknownCount, 1); ?>
                <div class="bot-stack">
                    <div class="stack-row">
                        <span>Human</span>
                        <div class="stack-track"><div class="stack-fill human" style="width: <?= percent_width($humanCount, $botMax) ?>%"></div></div>
                        <strong><?= number_format($humanCount) ?></strong>
                    </div>
                    <div class="stack-row">
                        <span>Bot</span>
                        <div class="stack-track"><div class="stack-fill bot" style="width: <?= percent_width($botCount, $botMax) ?>%"></div></div>
                        <strong><?= number_format($botCount) ?></strong>
                    </div>
                    <div class="stack-row">
                        <span>Unknown</span>
                        <div class="stack-track"><div class="stack-fill unknown" style="width: <?= percent_width($unknownCount, $botMax) ?>%"></div></div>
                        <strong><?= number_format($unknownCount) ?></strong>
                    </div>
                </div>
            </div>
        </section>

        <section class="panel">
            <div class="panel-body">
                <div class="split-header">
                    <div>
                        <div class="section-kicker">Traffic</div>
                        <h2 class="section-title">Top pages</h2>
                    </div>
                </div>
                <?php if ($topPages === []): ?>
                    <div class="empty">No pageviews yet.</div>
                <?php else: ?>
                    <div class="list">
                        <?php foreach ($topPages as $row): ?>
                            <?php $count = (int)$row['c']; ?>
                            <div>
                                <div class="row">
                                    <div>
                                        <div class="row-title"><?= htmlspecialchars(short_label((string)$row['page_url']), ENT_QUOTES, 'UTF-8') ?></div>
                                        <div class="row-meta"><?= htmlspecialchars((string)$row['page_url'], ENT_QUOTES, 'UTF-8') ?></div>
                                    </div>
                                    <div class="row-value"><?= number_format($count) ?></div>
                                </div>
                                <div class="meter"><span style="width: <?= percent_width($count, $topPagesMax) ?>%"></span></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </section>

        <section class="panel">
            <div class="panel-body">
                <div class="split-header">
                    <div>
                        <div class="section-kicker">Sources</div>
                        <h2 class="section-title">Top referrers</h2>
                    </div>
                </div>
                <?php if ($topReferrers === []): ?>
                    <div class="empty">No referrers yet.</div>
                <?php else: ?>
                    <div class="list">
                        <?php foreach ($topReferrers as $row): ?>
                            <?php $count = (int)$row['c']; ?>
                            <div>
                                <div class="row">
                                    <div>
                                        <div class="row-title"><?= htmlspecialchars(short_label((string)$row['referrer']), ENT_QUOTES, 'UTF-8') ?></div>
                                        <div class="row-meta"><?= htmlspecialchars((string)$row['referrer'], ENT_QUOTES, 'UTF-8') ?></div>
                                    </div>
                                    <div class="row-value"><?= number_format($count) ?></div>
                                </div>
                                <div class="meter green"><span style="width: <?= percent_width($count, $topRefMax) ?>%"></span></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </section>

        <section class="panel">
            <div class="panel-body">
                <div class="split-header">
                    <div>
                        <div class="section-kicker">Monetization</div>
                        <h2 class="section-title">Affiliate links</h2>
                    </div>
                </div>
                <?php if ($affiliateLinks === []): ?>
                    <div class="empty">No affiliate clicks recorded yet.</div>
                <?php else: ?>
                    <div class="list">
                        <?php foreach ($affiliateLinks as $row): ?>
                            <?php $count = (int)$row['c']; ?>
                            <div>
                                <div class="row">
                                    <div>
                                        <div class="row-title"><?= htmlspecialchars((string)($row['event_name'] ?: 'affiliate'), ENT_QUOTES, 'UTF-8') ?></div>
                                        <div class="row-meta"><?= htmlspecialchars((string)$row['event_value'], ENT_QUOTES, 'UTF-8') ?></div>
                                    </div>
                                    <div class="row-value"><?= number_format($count) ?></div>
                                </div>
                                <div class="meter"><span style="width: <?= percent_width($count, $affiliateMax) ?>%"></span></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </section>

        <section class="panel">
            <div class="panel-body">
                <div class="split-header">
                    <div>
                        <div class="section-kicker">Routing</div>
                        <h2 class="section-title">Redirect targets</h2>
                    </div>
                </div>
                <?php if ($redirects === []): ?>
                    <div class="empty">No redirect hits recorded yet.</div>
                <?php else: ?>
                    <div class="list">
                        <?php foreach ($redirects as $row): ?>
                            <?php $count = (int)$row['c']; ?>
                            <div>
                                <div class="row">
                                    <div>
                                        <div class="row-title"><?= htmlspecialchars((string)($row['event_name'] ?: 'redirect'), ENT_QUOTES, 'UTF-8') ?></div>
                                        <div class="row-meta"><?= htmlspecialchars((string)$row['event_value'], ENT_QUOTES, 'UTF-8') ?></div>
                                    </div>
                                    <div class="row-value"><?= number_format($count) ?></div>
                                </div>
                                <div class="meter blue"><span style="width: <?= percent_width($count, $redirectMax) ?>%"></span></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </section>
    </div>
</div>
</body>
</html>
