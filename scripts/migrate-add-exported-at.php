<?php

declare(strict_types=1);

/**
 * One-time migration: adds the exported_at column to the events table.
 *
 * Safe to run multiple times — exits 0 whether or not the column already exists.
 *
 * Usage:
 *   php scripts/migrate-add-exported-at.php
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

require_once __DIR__ . '/../lib/bootstrap.php';

$pdo = db();

// Check whether the column already exists.
$info = $pdo->query("PRAGMA table_info(events)")->fetchAll(PDO::FETCH_ASSOC);
$hasColumn = false;
foreach ($info as $col) {
    if ($col['name'] === 'exported_at') {
        $hasColumn = true;
        break;
    }
}

if ($hasColumn) {
    echo "[migrate] exported_at column already present — nothing to do.\n";
    exit(0);
}

$pdo->exec('ALTER TABLE events ADD COLUMN exported_at INTEGER DEFAULT NULL');
$pdo->exec('CREATE INDEX IF NOT EXISTS idx_events_exported_at ON events (exported_at)');

echo "[migrate] exported_at column added and indexed.\n";
exit(0);
