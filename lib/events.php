<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

function insert_event(PDO $pdo, array $event): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO events (event_type, event_name, event_value, page_url, referrer, site, user_agent, bot_class, created_at, client_ts)
         VALUES (:event_type, :event_name, :event_value, :page_url, :referrer, :site, :user_agent, :bot_class, :created_at, :client_ts)'
    );

    $stmt->execute([
        ':event_type' => (string)$event['event_type'],
        ':event_name' => (string)($event['event_name'] ?? ''),
        ':event_value' => (string)($event['event_value'] ?? ''),
        ':page_url' => (string)$event['page_url'],
        ':referrer' => (string)($event['referrer'] ?? ''),
        ':site' => (string)$event['site'],
        ':user_agent' => (string)($event['user_agent'] ?? ''),
        ':bot_class' => (string)$event['bot_class'],
        ':created_at' => (int)$event['created_at'],
        ':client_ts' => (string)($event['client_ts'] ?? ''),
    ]);
}
