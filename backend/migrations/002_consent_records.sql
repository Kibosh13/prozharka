PRAGMA foreign_keys = ON;

CREATE TABLE IF NOT EXISTS consent_records (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    order_id TEXT NOT NULL UNIQUE,
    personal_data_consent INTEGER NOT NULL CHECK (personal_data_consent = 1),
    offer_acceptance INTEGER NOT NULL CHECK (offer_acceptance = 1),
    privacy_version TEXT NOT NULL,
    consent_version TEXT NOT NULL,
    offer_version TEXT NOT NULL,
    cookie_choice TEXT NOT NULL CHECK (cookie_choice IN ('all', 'necessary', 'unset')),
    ip_address TEXT,
    user_agent TEXT,
    accepted_at TEXT NOT NULL,
    FOREIGN KEY (order_id) REFERENCES orders (id)
);

CREATE INDEX IF NOT EXISTS consent_records_accepted_at_idx
ON consent_records (accepted_at);
