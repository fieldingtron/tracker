<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/auth.php';

if (!app_is_installed()) {
    header('Location: /setup.php');
    exit;
}

if (is_admin_logged_in()) {
    header('Location: /admin/index.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    if (verify_admin_credentials($username, $password)) {
        start_session_if_needed();
        $_SESSION['admin_logged_in'] = true;
        $_SESSION['admin_username'] = $username;
        header('Location: /admin/index.php');
        exit;
    }

    $error = 'Invalid credentials';
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Analytics Admin Login</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; background: #f6f7fb; color: #222; }
        .card { width: 320px; margin: 10vh auto; background: #fff; border: 1px solid #ddd; border-radius: 8px; padding: 20px; }
        label, input { display: block; width: 100%; }
        input { margin: 6px 0 14px; padding: 8px; border: 1px solid #bbb; border-radius: 6px; }
        button { width: 100%; padding: 10px; border: 0; border-radius: 6px; background: #1f5eff; color: #fff; cursor: pointer; }
        .error { background: #ffe9e9; color: #900; border: 1px solid #f3bbbb; padding: 8px; border-radius: 6px; margin-bottom: 12px; }
        .muted { color: #666; font-size: 12px; }
    </style>
</head>
<body>
<div class="card">
    <h1>Analytics Admin</h1>
    <?php if ($error !== ''): ?>
        <div class="error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>
    <form method="post">
        <label for="username">Username</label>
        <input id="username" name="username" required>

        <label for="password">Password</label>
        <input id="password" type="password" name="password" required>

        <button type="submit">Sign in</button>
    </form>
    <p class="muted">Credentials are set during <code>/setup.php</code>.</p>
</div>
</body>
</html>
