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
$last7Start = $now - (7 * 86400);

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

$todayTotal = scalar_query($pdo, 'SELECT COUNT(*) FROM events WHERE created_at >= :start', [':start' => $todayStart]);
$last7Total = scalar_query($pdo, 'SELECT COUNT(*) FROM events WHERE created_at >= :start', [':start' => $last7Start]);

$botMixStmt = $pdo->prepare(
    'SELECT bot_class, COUNT(*) AS c
     FROM events
     WHERE created_at >= :start
     GROUP BY bot_class'
);
$botMixStmt->execute([':start' => $last7Start]);
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
$eventCountsStmt->execute([':start' => $last7Start]);
$eventCounts = $eventCountsStmt->fetchAll();

$topPagesStmt = $pdo->prepare(
    'SELECT page_url, COUNT(*) AS c
     FROM events
     WHERE created_at >= :start
     GROUP BY page_url
     ORDER BY c DESC
     LIMIT 15'
);
$topPagesStmt->execute([':start' => $last7Start]);
$topPages = $topPagesStmt->fetchAll();

$topRefStmt = $pdo->prepare(
    'SELECT referrer, COUNT(*) AS c
     FROM events
     WHERE created_at >= :start
       AND referrer IS NOT NULL
       AND referrer != ""
     GROUP BY referrer
     ORDER BY c DESC
     LIMIT 15'
);
$topRefStmt->execute([':start' => $last7Start]);
$topReferrers = $topRefStmt->fetchAll();

$dailyStmt = $pdo->prepare(
    "SELECT date(created_at, 'unixepoch', 'localtime') AS day, COUNT(*) AS c
     FROM events
     WHERE created_at >= :start
     GROUP BY day
     ORDER BY day DESC"
);
$dailyStmt->execute([':start' => $last7Start]);
$daily = $dailyStmt->fetchAll();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Tiny Analytics</title>
    <style>
        :root {
            --bg: #f5f7fb;
            --panel: #ffffff;
            --text: #1a202c;
            --muted: #5a6475;
            --line: #d6deea;
            --accent: #1565d8;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            background: var(--bg);
            color: var(--text);
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            line-height: 1.4;
        }
        .wrap {
            max-width: 1100px;
            margin: 0 auto;
            padding: 18px;
        }
        .top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            margin-bottom: 12px;
        }
        h1 {
            margin: 0;
            font-size: 22px;
        }
        .sub {
            color: var(--muted);
            font-size: 13px;
            margin-top: 2px;
        }
        .btn {
            display: inline-block;
            text-decoration: none;
            color: var(--text);
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 8px;
            padding: 8px 12px;
            font-size: 14px;
        }
        .kpis {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
            gap: 10px;
            margin-bottom: 10px;
        }
        .card {
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 10px;
            padding: 12px;
        }
        .k {
            color: var(--muted);
            text-transform: uppercase;
            letter-spacing: .05em;
            font-size: 11px;
        }
        .v {
            margin-top: 4px;
            font-size: 28px;
            font-weight: 700;
        }
        .grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            margin-top: 10px;
        }
        h2 {
            margin: 0 0 10px;
            font-size: 15px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }
        th, td {
            padding: 8px 4px;
            border-bottom: 1px solid var(--line);
            text-align: left;
            vertical-align: top;
        }
        th { color: var(--muted); font-weight: 600; }
        .mono {
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, monospace;
            word-break: break-all;
        }
        .empty { color: var(--muted); font-size: 13px; }
        @media (max-width: 900px) {
            .grid { grid-template-columns: 1fr; }
            .top { align-items: flex-start; }
        }
    </style>
</head>
<body>
<div class="wrap">
    <div class="top">
        <div>
            <h1>Tiny Analytics</h1>
            <div class="sub">Site: <?= htmlspecialchars($siteLabel, ENT_QUOTES, 'UTF-8') ?> · Window: last 7 days</div>
        </div>
        <a class="btn" href="/admin/logout.php">Sign out</a>
    </div>

    <div class="kpis">
        <div class="card">
            <div class="k">Events Today</div>
            <div class="v"><?= number_format($todayTotal) ?></div>
        </div>
        <div class="card">
            <div class="k">Events 7 Days</div>
            <div class="v"><?= number_format($last7Total) ?></div>
        </div>
        <div class="card">
            <div class="k">Human (7d)</div>
            <div class="v"><?= number_format($humanCount) ?></div>
        </div>
        <div class="card">
            <div class="k">Bot (7d)</div>
            <div class="v"><?= number_format($botCount) ?></div>
        </div>
        <div class="card">
            <div class="k">Unknown (7d)</div>
            <div class="v"><?= number_format($unknownCount) ?></div>
        </div>
    </div>

    <div class="grid">
        <div class="card">
            <h2>Event Counts (7d)</h2>
            <?php if ($eventCounts === []): ?>
                <div class="empty">No events yet.</div>
            <?php else: ?>
                <table>
                    <thead><tr><th>Event Type</th><th>Count</th></tr></thead>
                    <tbody>
                    <?php foreach ($eventCounts as $row): ?>
                        <tr>
                            <td><?= htmlspecialchars((string)$row['event_type'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= number_format((int)$row['c']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <div class="card">
            <h2>Daily Events (7d)</h2>
            <?php if ($daily === []): ?>
                <div class="empty">No events yet.</div>
            <?php else: ?>
                <table>
                    <thead><tr><th>Day</th><th>Count</th></tr></thead>
                    <tbody>
                    <?php foreach ($daily as $row): ?>
                        <tr>
                            <td><?= htmlspecialchars((string)$row['day'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= number_format((int)$row['c']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

    <div class="grid">
        <div class="card">
            <h2>Top Pages (7d)</h2>
            <?php if ($topPages === []): ?>
                <div class="empty">No pageviews yet.</div>
            <?php else: ?>
                <table>
                    <thead><tr><th>Page URL</th><th>Count</th></tr></thead>
                    <tbody>
                    <?php foreach ($topPages as $row): ?>
                        <tr>
                            <td class="mono"><?= htmlspecialchars((string)$row['page_url'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= number_format((int)$row['c']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <div class="card">
            <h2>Top Referrers (7d)</h2>
            <?php if ($topReferrers === []): ?>
                <div class="empty">No referrers yet.</div>
            <?php else: ?>
                <table>
                    <thead><tr><th>Referrer</th><th>Count</th></tr></thead>
                    <tbody>
                    <?php foreach ($topReferrers as $row): ?>
                        <tr>
                            <td class="mono"><?= htmlspecialchars((string)$row['referrer'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= number_format((int)$row['c']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
</div>
</body>
</html>
