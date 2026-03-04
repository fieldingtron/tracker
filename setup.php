<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/bootstrap.php';

$errors = [];
$lockPath = install_lock_path();
$installed = app_is_installed() || is_file($lockPath);

$form = [
    'db_path' => 'data/analytics.sqlite',
    'primary_site' => '',
    'allowed_origins' => '',
    'extra_sites' => '',
    'admin_username' => 'admin',
];

function split_values(string $value): array
{
    $value = trim($value);
    if ($value === '') {
        return [];
    }

    $parts = preg_split('/[\r\n,]+/', $value) ?: [];
    $result = [];
    foreach ($parts as $part) {
        $item = trim($part);
        if ($item !== '') {
            $result[] = $item;
        }
    }

    return $result;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$installed) {
    $form['db_path'] = trim((string)($_POST['db_path'] ?? 'data/analytics.sqlite'));
    $form['primary_site'] = trim((string)($_POST['primary_site'] ?? ''));
    $form['allowed_origins'] = trim((string)($_POST['allowed_origins'] ?? ''));
    $form['extra_sites'] = trim((string)($_POST['extra_sites'] ?? ''));
    $form['admin_username'] = trim((string)($_POST['admin_username'] ?? 'admin'));
    $password = (string)($_POST['admin_password'] ?? '');
    $passwordConfirm = (string)($_POST['admin_password_confirm'] ?? '');

    if ($form['db_path'] === '') {
        $errors[] = 'Database path is required.';
    }

    $primarySite = normalize_site_host($form['primary_site']);
    if ($primarySite === '') {
        $errors[] = 'Primary site must be a valid domain like example.com.';
    }

    if (preg_match('/^[A-Za-z0-9_.-]{3,64}$/', $form['admin_username']) !== 1) {
        $errors[] = 'Admin username must be 3-64 chars: letters, numbers, _, ., -';
    }

    if (strlen($password) < 10) {
        $errors[] = 'Admin password must be at least 10 characters.';
    }

    if (!hash_equals($password, $passwordConfirm)) {
        $errors[] = 'Admin password and confirmation must match.';
    }

    $allowedSitesMap = [];
    if ($primarySite !== '') {
        $allowedSitesMap[$primarySite] = true;
    }
    foreach (split_values($form['extra_sites']) as $siteValue) {
        $siteHost = normalize_site_host($siteValue);
        if ($siteHost === '') {
            $errors[] = 'Invalid allowed site: ' . $siteValue;
            continue;
        }
        $allowedSitesMap[$siteHost] = true;
    }
    $allowedSites = array_keys($allowedSitesMap);

    $originInput = split_values($form['allowed_origins']);
    if ($originInput === [] && $primarySite !== '') {
        $originInput[] = 'https://' . $primarySite;
    }

    $allowedOriginsMap = [];
    foreach ($originInput as $originValue) {
        $candidate = $originValue;
        if (preg_match('#^https?://#i', $candidate) !== 1) {
            $candidate = 'https://' . $candidate;
        }
        $normalized = normalize_origin($candidate);
        if ($normalized === '') {
            $errors[] = 'Invalid origin: ' . $originValue;
            continue;
        }
        $allowedOriginsMap[$normalized] = true;
    }
    $allowedOrigins = array_keys($allowedOriginsMap);

    $dbAbsolute = resolve_db_path($form['db_path']);
    $dbDir = dirname($dbAbsolute);

    if (!is_dir($dbDir) && !mkdir($dbDir, 0755, true) && !is_dir($dbDir)) {
        $errors[] = 'Could not create DB directory: ' . $dbDir;
    }

    if ($errors === []) {
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);

        if ($passwordHash === false) {
            $errors[] = 'Failed to generate password hash.';
        } else {
            $configData = [
                'db_path' => $form['db_path'],
                'primary_site' => $primarySite,
                'allowed_sites' => $allowedSites,
                'allowed_origins' => $allowedOrigins,
                'admin_username' => $form['admin_username'],
                'admin_password_hash' => $passwordHash,
            ];

            $configText = "<?php\n\n";
            $configText .= "declare(strict_types=1);\n\n";
            $configText .= 'return ' . var_export($configData, true) . ";\n";

            $configFile = config_path();
            $wroteConfig = false;

            try {
                if (file_put_contents($configFile, $configText, LOCK_EX) === false) {
                    throw new RuntimeException('Failed to write config.php');
                }
                $wroteConfig = true;

                $pdo = new PDO('sqlite:' . $dbAbsolute, null, null, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]);
                ensure_schema($pdo);

                if (file_put_contents($lockPath, "installed_at=" . date('c') . "\n", LOCK_EX) === false) {
                    throw new RuntimeException('Failed to write install lock file');
                }

                $installed = true;
            } catch (Throwable $e) {
                if ($wroteConfig) {
                    @unlink($configFile);
                }
                $errors[] = 'Setup failed. Check file permissions and try again.';
            }
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Tiny Analytics Setup</title>
    <style>
        :root {
            --bg: #f4f6fb;
            --panel: #ffffff;
            --line: #d8dfeb;
            --text: #1b2430;
            --muted: #5f6c7f;
            --accent: #1565d8;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            background: var(--bg);
            color: var(--text);
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            line-height: 1.45;
            padding: 18px;
        }
        .wrap { max-width: 760px; margin: 0 auto; }
        .panel {
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 10px;
            padding: 16px;
            margin-bottom: 12px;
        }
        h1 { margin: 0 0 6px; font-size: 24px; }
        p { margin: 0 0 10px; color: var(--muted); }
        label { display: block; font-size: 13px; margin: 10px 0 4px; }
        input, textarea {
            width: 100%;
            border: 1px solid #bbc6d6;
            border-radius: 8px;
            padding: 10px;
            font: inherit;
        }
        textarea { min-height: 70px; resize: vertical; }
        .row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
        }
        .btn {
            border: 0;
            border-radius: 8px;
            background: var(--accent);
            color: #fff;
            padding: 10px 14px;
            font: inherit;
            cursor: pointer;
            margin-top: 14px;
        }
        .error {
            background: #fff3f3;
            border: 1px solid #f0bebe;
            color: #8f1212;
            border-radius: 8px;
            padding: 10px;
            margin-bottom: 10px;
        }
        .ok {
            background: #effaf2;
            border: 1px solid #bce7c8;
            color: #1c6834;
            border-radius: 8px;
            padding: 10px;
            margin-bottom: 10px;
        }
        .mono { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, monospace; }
        @media (max-width: 720px) {
            .row { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
<div class="wrap">
    <div class="panel">
        <h1>Tiny Analytics Setup</h1>
        <p>Run this once to create <span class="mono">config.php</span> and initialize SQLite.</p>

        <?php if ($installed): ?>
            <div class="ok">
                Setup is complete. Open <a href="/admin/login.php">admin login</a>.
            </div>
            <p>If you need to reinstall, delete <span class="mono">config.php</span> and <span class="mono"><?= htmlspecialchars($lockPath, ENT_QUOTES, 'UTF-8') ?></span>.</p>
        <?php else: ?>
            <?php if ($errors !== []): ?>
                <div class="error">
                    <?php foreach ($errors as $err): ?>
                        <div><?= htmlspecialchars($err, ENT_QUOTES, 'UTF-8') ?></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <form method="post">
                <label for="db_path">SQLite DB path (relative to app root or absolute)</label>
                <input id="db_path" name="db_path" value="<?= htmlspecialchars($form['db_path'], ENT_QUOTES, 'UTF-8') ?>" required>

                <label for="primary_site">Primary site/domain</label>
                <input id="primary_site" name="primary_site" placeholder="example.com" value="<?= htmlspecialchars($form['primary_site'], ENT_QUOTES, 'UTF-8') ?>" required>

                <label for="allowed_origins">Allowed origins (comma or newline separated)</label>
                <textarea id="allowed_origins" name="allowed_origins" placeholder="https://example.com"><?= htmlspecialchars($form['allowed_origins'], ENT_QUOTES, 'UTF-8') ?></textarea>

                <label for="extra_sites">Extra allowed site hosts (optional)</label>
                <input id="extra_sites" name="extra_sites" placeholder="www.example.com" value="<?= htmlspecialchars($form['extra_sites'], ENT_QUOTES, 'UTF-8') ?>">

                <div class="row">
                    <div>
                        <label for="admin_username">Admin username</label>
                        <input id="admin_username" name="admin_username" value="<?= htmlspecialchars($form['admin_username'], ENT_QUOTES, 'UTF-8') ?>" required>
                    </div>
                    <div></div>
                </div>

                <div class="row">
                    <div>
                        <label for="admin_password">Admin password</label>
                        <input id="admin_password" type="password" name="admin_password" required>
                    </div>
                    <div>
                        <label for="admin_password_confirm">Confirm password</label>
                        <input id="admin_password_confirm" type="password" name="admin_password_confirm" required>
                    </div>
                </div>

                <button class="btn" type="submit">Create config and initialize database</button>
            </form>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
