PRAGMA journal_mode = WAL;

CREATE TABLE IF NOT EXISTS events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    event_type TEXT NOT NULL CHECK (event_type IN ('pageview', 'click', 'custom')),
    event_name TEXT,
    event_value TEXT,
    page_url TEXT NOT NULL,
    referrer TEXT,
    site TEXT NOT NULL,
    user_agent TEXT,
    bot_class TEXT NOT NULL CHECK (bot_class IN ('human', 'bot', 'unknown')),
    created_at INTEGER NOT NULL,
    client_ts TEXT
);

CREATE INDEX IF NOT EXISTS idx_events_created_at ON events(created_at);
CREATE INDEX IF NOT EXISTS idx_events_site ON events(site);
CREATE INDEX IF NOT EXISTS idx_events_event_type ON events(event_type);
CREATE INDEX IF NOT EXISTS idx_events_bot_class ON events(bot_class);
CREATE INDEX IF NOT EXISTS idx_events_page_url ON events(page_url);
CREATE INDEX IF NOT EXISTS idx_events_referrer ON events(referrer);
