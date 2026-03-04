<?php

declare(strict_types=1);

function app_root_path(): string
{
    return dirname(__DIR__);
}

function config_path(): string
{
    return app_root_path() . '/config.php';
}

function schema_path(): string
{
    return app_root_path() . '/schema.sql';
}

function install_lock_path(): string
{
    return app_root_path() . '/data/install.lock';
}

function app_is_installed(): bool
{
    return is_file(config_path());
}

function is_absolute_path(string $path): bool
{
    return $path !== '' && ($path[0] === '/' || preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1);
}

function resolve_db_path(string $dbPath): string
{
    if (is_absolute_path($dbPath)) {
        return $dbPath;
    }

    return app_root_path() . '/' . ltrim($dbPath, '/');
}

function app_config(): array
{
    static $config;

    if ($config !== null) {
        return $config;
    }

    $configFile = config_path();
    if (!is_file($configFile)) {
        throw new RuntimeException('App is not installed. Run setup.php first.');
    }

    $config = require $configFile;

    if (!is_array($config)) {
        throw new RuntimeException('Invalid config file.');
    }

    return $config;
}

function db(): PDO
{
    static $pdo;

    if ($pdo !== null) {
        return $pdo;
    }

    $config = app_config();
    $dbPath = resolve_db_path((string)($config['db_path'] ?? 'data/analytics.sqlite'));
    $dbDir = dirname($dbPath);

    if (!is_dir($dbDir)) {
        mkdir($dbDir, 0755, true);
    }

    $pdo = new PDO('sqlite:' . $dbPath, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    return $pdo;
}

function ensure_schema(PDO $pdo): void
{
    $schema = file_get_contents(schema_path());
    if ($schema === false) {
        throw new RuntimeException('Could not read schema.sql');
    }

    $pdo->exec($schema);
}

function normalize_site_host(string $input): string
{
    $value = strtolower(trim($input));
    if ($value === '') {
        return '';
    }

    if (preg_match('#^https?://#', $value) === 1) {
        $host = (string)parse_url($value, PHP_URL_HOST);
    } else {
        $host = preg_replace('#/.*$#', '', $value) ?? '';
        $host = preg_replace('/:\d+$/', '', $host) ?? '';
    }

    $host = strtolower(trim($host));
    if ($host === '' || preg_match('/^[a-z0-9.-]+$/', $host) !== 1) {
        return '';
    }

    return $host;
}

function normalize_origin(string $input): string
{
    $value = trim($input);
    if ($value === '') {
        return '';
    }

    $parts = parse_url($value);
    if (!is_array($parts)) {
        return '';
    }

    $scheme = strtolower((string)($parts['scheme'] ?? ''));
    $host = strtolower((string)($parts['host'] ?? ''));
    $port = isset($parts['port']) ? ':' . (int)$parts['port'] : '';

    if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
        return '';
    }

    return $scheme . '://' . $host . $port;
}

function site_allowlist(array $config): array
{
    $sites = $config['allowed_sites'] ?? [];
    if (!is_array($sites)) {
        $sites = [];
    }

    $primary = normalize_site_host((string)($config['primary_site'] ?? ''));
    if ($primary !== '') {
        $sites[] = $primary;
    }

    $normalized = [];
    foreach ($sites as $site) {
        $host = normalize_site_host((string)$site);
        if ($host !== '') {
            $normalized[$host] = true;
        }
    }

    return array_keys($normalized);
}

function origin_allowlist(array $config): array
{
    $origins = $config['allowed_origins'] ?? [];
    if (!is_array($origins)) {
        $origins = [];
    }

    $normalized = [];
    foreach ($origins as $origin) {
        $value = normalize_origin((string)$origin);
        if ($value !== '') {
            $normalized[$value] = true;
        }
    }

    return array_keys($normalized);
}
