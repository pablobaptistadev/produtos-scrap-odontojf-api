const MIGRATIONS: Array<{ id: string; sql: string }> = [
  {
    id: "001_init",
    sql: `
      CREATE TABLE IF NOT EXISTS products (
        sku                 TEXT PRIMARY KEY,
        slug                TEXT UNIQUE NOT NULL,
        source_url          TEXT NOT NULL,
        sku_provisional     INTEGER NOT NULL DEFAULT 0,
        scrape_json         TEXT,
        scrape_status       TEXT NOT NULL DEFAULT 'pending',
        scrape_updated_at   TEXT,
        scrape_error        TEXT,
        erp_json            TEXT,
        erp_status          TEXT NOT NULL DEFAULT 'pending',
        erp_updated_at      TEXT,
        erp_error           TEXT,
        merged_json         TEXT,
        merged_updated_at   TEXT,
        woo_product_id      INTEGER,
        woo_status          TEXT NOT NULL DEFAULT 'pending',
        woo_last_response   TEXT,
        woo_updated_at      TEXT,
        woo_error           TEXT,
        created_at          TEXT NOT NULL DEFAULT (datetime('now'))
      );
      CREATE INDEX IF NOT EXISTS idx_products_woo_status    ON products(woo_status);
      CREATE INDEX IF NOT EXISTS idx_products_scrape_status ON products(scrape_status);
      CREATE INDEX IF NOT EXISTS idx_products_erp_status    ON products(erp_status);
    `,
  },
  {
    id: "002_sync_queue",
    sql: `
      CREATE TABLE IF NOT EXISTS sync_queue (
        id              INTEGER PRIMARY KEY AUTOINCREMENT,
        sku             TEXT,
        slug            TEXT,
        url             TEXT,
        stage           TEXT NOT NULL,
        status          TEXT NOT NULL DEFAULT 'pending',
        attempts        INTEGER NOT NULL DEFAULT 0,
        last_error      TEXT,
        payload_json    TEXT,
        created_at      TEXT NOT NULL DEFAULT (datetime('now')),
        started_at      TEXT,
        finished_at     TEXT,
        next_retry_at   TEXT
      );
      CREATE INDEX IF NOT EXISTS idx_sync_queue_status ON sync_queue(status, stage);
      CREATE INDEX IF NOT EXISTS idx_sync_queue_sku    ON sync_queue(sku);
    `,
  },
  {
    id: "003_sync_events",
    sql: `
      CREATE TABLE IF NOT EXISTS sync_events (
        id           INTEGER PRIMARY KEY AUTOINCREMENT,
        sku          TEXT,
        stage        TEXT NOT NULL,
        level        TEXT NOT NULL,
        message      TEXT NOT NULL,
        context_json TEXT,
        created_at   TEXT NOT NULL DEFAULT (datetime('now'))
      );
      CREATE INDEX IF NOT EXISTS idx_sync_events_created ON sync_events(created_at);
      CREATE INDEX IF NOT EXISTS idx_sync_events_sku     ON sync_events(sku);
    `,
  },
  {
    id: "004_app_state",
    sql: `
      CREATE TABLE IF NOT EXISTS app_state (
        key        TEXT PRIMARY KEY,
        value      TEXT NOT NULL,
        updated_at TEXT NOT NULL DEFAULT (datetime('now'))
      );
    `,
  },
  {
    id: "005_migration_log",
    sql: `
      CREATE TABLE IF NOT EXISTS schema_migrations (
        id         TEXT PRIMARY KEY,
        applied_at TEXT NOT NULL DEFAULT (datetime('now'))
      );
    `,
  },
  {
    id: "006_external_sku",
    sql: `ALTER TABLE products ADD COLUMN external_sku TEXT`,
  },
  {
    // Queue hygiene:
    //   1) Collapse the backlog to one active row per (stage, sku).
    //   2) Keep it that way with a partial unique index. SQLite treats
    //      NULL skus as distinct in a unique index, so it does NOT block
    //      concurrent rebuild rows; the enqueueRebuild idempotency guard covers
    //      that case instead.
    //   3) Cleanup index for the retention purge (status, finished_at).
    id: "007_sync_queue_dedup_retention",
    sql: `
      DELETE FROM sync_queue
       WHERE status IN ('pending','processing','failed')
         AND id NOT IN (
           SELECT MAX(id) FROM sync_queue
            WHERE status IN ('pending','processing','failed')
            GROUP BY stage, COALESCE(sku, '')
         );
      CREATE UNIQUE INDEX IF NOT EXISTS uniq_sync_queue_active
        ON sync_queue(sku, stage)
        WHERE status IN ('pending','processing','failed');
      CREATE INDEX IF NOT EXISTS idx_sync_queue_cleanup
        ON sync_queue(status, finished_at);
    `,
  },
  {
    // Woo Bridge: per-product tracking of the WordPress-side api_queue.
    //   woo_queue_id     — the id returned by the plugin's {queued, queue_id}
    //   woo_duration_ms  — the WP-side handler execution_time_ms (ground truth)
    //   woo_queue_status — pending|processing|completed|passed|failed (WP terminal)
    //   woo_pushed_at    — when the Worker POSTed to the plugin
    // (woo_product_id / woo_status / woo_last_response / woo_error /
    //  woo_updated_at already exist from 001_init.)
    id: "008_woo_bridge",
    sql: `
      ALTER TABLE products ADD COLUMN woo_queue_id     INTEGER;
      ALTER TABLE products ADD COLUMN woo_duration_ms  INTEGER;
      ALTER TABLE products ADD COLUMN woo_queue_status TEXT;
      ALTER TABLE products ADD COLUMN woo_pushed_at    TEXT;
    `,
  },
];

export async function runMigrations(db: D1Database): Promise<{ applied: string[] }> {
  await db.exec("CREATE TABLE IF NOT EXISTS schema_migrations (id TEXT PRIMARY KEY, applied_at TEXT NOT NULL DEFAULT (datetime('now')))");
  const existing = await db.prepare("SELECT id FROM schema_migrations").all<{ id: string }>();
  const seen = new Set(existing.results.map((r) => r.id));
  const applied: string[] = [];

  for (const migration of MIGRATIONS) {
    if (seen.has(migration.id)) continue;
    const statements = migration.sql
      .split(";")
      .map((s) => s.trim())
      .filter((s) => s.length > 0);
    for (const stmt of statements) {
      await db.exec(stmt.replace(/\s+/g, " "));
    }
    await db.prepare("INSERT INTO schema_migrations (id) VALUES (?)").bind(migration.id).run();
    applied.push(migration.id);
  }
  return { applied };
}
