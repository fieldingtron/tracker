<?php

declare(strict_types=1);

return [
    // Use an absolute path to keep DB outside the web root when possible.
    'db_path' => 'data/analytics.sqlite',

    // One-site-per-instance default.
    'primary_site' => 'example.com',
    'allowed_sites' => ['example.com'],
    'allowed_origins' => ['https://example.com'],

    // Admin login credentials (password hash only).
    'admin_username' => 'admin',
    'admin_password_hash' => '$2y$10$replace.with.password_hash.output',

    // Umami event export (used by scripts/umami-export.php).
    // Leave umami_api_key empty string if your Umami instance has no auth requirement.
    'umami_url'        => 'https://umami.example.com',
    'umami_website_id' => 'xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx',
    'umami_api_key'    => '',
];
