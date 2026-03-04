<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

function start_session_if_needed(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
}

function is_admin_logged_in(): bool
{
    start_session_if_needed();
    return !empty($_SESSION['admin_logged_in']);
}

function verify_admin_credentials(string $username, string $password): bool
{
    $config = app_config();
    $expectedUser = (string)($config['admin_username'] ?? 'admin');

    if (!hash_equals($expectedUser, $username)) {
        return false;
    }

    $hash = (string)($config['admin_password_hash'] ?? '');
    return $hash !== '' && password_verify($password, $hash);
}

function require_admin_auth(): void
{
    if (!app_is_installed()) {
        header('Location: /setup.php');
        exit;
    }

    if (is_admin_logged_in()) {
        return;
    }

    header('Location: /admin/login.php');
    exit;
}
