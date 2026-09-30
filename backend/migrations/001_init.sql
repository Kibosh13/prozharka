PRAGMA foreign_keys = ON;

CREATE TABLE IF NOT EXISTS customers (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    full_name TEXT NOT NULL,
    phone TEXT NOT NULL,
    email TEXT NOT NULL,
    telegram_username TEXT NOT NULL,
    telegram_user_id INTEGER,
    private_chat_id INTEGER,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    UNIQUE (phone, email)
);

CREATE UNIQUE INDEX IF NOT EXISTS customers_telegram_user_id_uq
ON customers (telegram_user_id)
WHERE telegram_user_id IS NOT NULL;

CREATE TABLE IF NOT EXISTS orders (
    id TEXT PRIMARY KEY,
    public_token_hash TEXT NOT NULL UNIQUE,
    customer_id INTEGER NOT NULL,
    provider_order_id TEXT NOT NULL UNIQUE,
    provider_subscription_id TEXT,
    status TEXT NOT NULL DEFAULT 'pending'
        CHECK (status IN ('pending', 'paid', 'cancelled', 'failed')),
    amount TEXT NOT NULL,
    currency TEXT NOT NULL DEFAULT 'RUB',
    invite_link TEXT,
    invite_created_at TEXT,
    invite_expires_at TEXT,
    paid_at TEXT,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    FOREIGN KEY (customer_id) REFERENCES customers (id)
);

CREATE UNIQUE INDEX IF NOT EXISTS orders_invite_link_uq
ON orders (invite_link)
WHERE invite_link IS NOT NULL AND invite_link <> '';

CREATE TABLE IF NOT EXISTS subscriptions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    customer_id INTEGER NOT NULL UNIQUE,
    provider_subscription_id TEXT,
    status TEXT NOT NULL DEFAULT 'pending'
        CHECK (status IN ('pending', 'active', 'past_due', 'cancelled', 'expired')),
    expires_at TEXT,
    last_paid_at TEXT,
    managed_by_bot INTEGER NOT NULL DEFAULT 0 CHECK (managed_by_bot IN (0, 1)),
    protected_existing INTEGER NOT NULL DEFAULT 0 CHECK (protected_existing IN (0, 1)),
    joined_at TEXT,
    removed_at TEXT,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    FOREIGN KEY (customer_id) REFERENCES customers (id)
);

CREATE UNIQUE INDEX IF NOT EXISTS subscriptions_provider_subscription_id_uq
ON subscriptions (provider_subscription_id)
WHERE provider_subscription_id IS NOT NULL AND provider_subscription_id <> '';

CREATE TABLE IF NOT EXISTS payment_events (
    fingerprint TEXT PRIMARY KEY,
    provider_order_id TEXT,
    provider_subscription_id TEXT,
    order_id TEXT,
    payment_status TEXT NOT NULL,
    amount TEXT,
    paid_at TEXT,
    received_at TEXT NOT NULL,
    FOREIGN KEY (order_id) REFERENCES orders (id)
);

CREATE TABLE IF NOT EXISTS telegram_updates (
    update_id INTEGER PRIMARY KEY,
    received_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS access_jobs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    job_type TEXT NOT NULL CHECK (job_type IN ('issue_invite', 'remove_member')),
    customer_id INTEGER NOT NULL,
    order_id TEXT,
    status TEXT NOT NULL DEFAULT 'pending'
        CHECK (status IN ('pending', 'running', 'done', 'dead')),
    run_after TEXT NOT NULL,
    attempts INTEGER NOT NULL DEFAULT 0,
    last_error TEXT,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    FOREIGN KEY (customer_id) REFERENCES customers (id),
    FOREIGN KEY (order_id) REFERENCES orders (id)
);

CREATE INDEX IF NOT EXISTS access_jobs_pending_idx
ON access_jobs (status, run_after);

