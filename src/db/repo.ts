import type { Env } from "../env";
import { nowIso, safeJsonParse } from "../core";

export interface ProductRow {
  sku: string;
  slug: string;
  source_url: string;
  sku_provisional: number;
  scrape_json: string | null;
  external_sku: string | null;
  scrape_status: string;
  scrape_updated_at: string | null;
  scrape_error: string | null;
  erp_json: string | null;
  erp_status: string;
  erp_updated_at: string | null;
  erp_error: string | null;
  merged_json: string | null;
  merged_updated_at: string | null;
  woo_product_id: number | null;
  woo_status: string;
  woo_last_response: string | null;
  woo_updated_at: string | null;
  woo_error: string | null;
  /** Woo Bridge (migration 008): the WordPress-side api_queue job. */
  woo_queue_id: number | null;
  woo_queue_status: string | null;
  woo_duration_ms: number | null;
  woo_pushed_at: string | null;
  created_at: string;
}

export interface SyncQueueRow {
  id: number;
  sku: string | null;
  slug: string | null;
  url: string | null;
  stage: string;
  status: string;
  attempts: number;
  last_error: string | null;
  payload_json: string | null;
  created_at: string;
  started_at: string | null;
  finished_at: string | null;
  next_retry_at: string | null;
}

export async function upsertProductFromSitemap(
  env: Env,
  entry: { sku: string; slug: string; sourceUrl: string; provisional: boolean },
): Promise<void> {
  await env.DB.prepare(
    `INSERT INTO products (sku, slug, source_url, sku_provisional)
     VALUES (?, ?, ?, ?)
     ON CONFLICT(sku) DO UPDATE SET
       slug = excluded.slug,
       source_url = excluded.source_url,
       sku_provisional = excluded.sku_provisional`,
  )
    .bind(entry.sku, entry.slug, entry.sourceUrl, entry.provisional ? 1 : 0)
    .run();
}

export async function updateScrapeResult(
  env: Env,
  sku: string,
  result: { status: "ok" | "failed" | "skipped"; json?: unknown; error?: string; externalSku?: string | null },
): Promise<void> {
  const ts = nowIso();
  await env.DB.prepare(
    `UPDATE products
       SET scrape_json = ?,
           scrape_status = ?,
           scrape_updated_at = ?,
           scrape_error = ?,
           external_sku = COALESCE(?, external_sku)
     WHERE sku = ?`,
  )
    .bind(
      result.status === "ok" ? JSON.stringify(result.json ?? null) : null,
      result.status,
      ts,
      result.error ?? null,
      result.externalSku ?? null,
      sku,
    )
    .run();
}

export async function updateErpResult(
  env: Env,
  sku: string,
  result: { status: "ok" | "failed" | "skipped"; json?: unknown; error?: string },
): Promise<void> {
  const ts = nowIso();
  await env.DB.prepare(
    `UPDATE products
       SET erp_json = ?,
           erp_status = ?,
           erp_updated_at = ?,
           erp_error = ?
     WHERE sku = ?`,
  )
    .bind(
      result.status === "ok" ? JSON.stringify(result.json ?? null) : null,
      result.status,
      ts,
      result.error ?? null,
      sku,
    )
    .run();
}

export async function updateMergedResult(env: Env, sku: string, merged: unknown): Promise<void> {
  await env.DB.prepare(
    `UPDATE products
       SET merged_json = ?,
           merged_updated_at = ?
     WHERE sku = ?`,
  )
    .bind(JSON.stringify(merged), nowIso(), sku)
    .run();
}

export async function updateWooResult(
  env: Env,
  sku: string,
  result: { status: "ok" | "failed" | "skipped"; productId?: number; response?: unknown; error?: string },
): Promise<void> {
  const ts = nowIso();
  await env.DB.prepare(
    `UPDATE products
       SET woo_product_id = COALESCE(?, woo_product_id),
           woo_status = ?,
           woo_last_response = ?,
           woo_updated_at = ?,
           woo_error = ?
     WHERE sku = ?`,
  )
    .bind(
      result.productId ?? null,
      result.status,
      result.response ? JSON.stringify(result.response) : null,
      ts,
      result.error ?? null,
      sku,
    )
    .run();
}

/**
 * Woo Bridge variant of `updateWooResult`.
 *
 * The bridge plugin answers with a queue receipt rather than a product, so the
 * push stage lands here twice: once when the job is accepted (status
 * `processing` + `queue_id`), and again when polling resolves it to a terminal
 * state. Every column is COALESCE'd so a later partial update never erases what
 * the first one wrote — `woo_error` is the deliberate exception, since a retry
 * that succeeds must clear the previous error.
 */
export async function updateWooQueueResult(
  env: Env,
  sku: string,
  result: {
    status?: "ok" | "failed" | "skipped" | "processing";
    productId?: number | null;
    queueId?: number | null;
    queueStatus?: string | null;
    durationMs?: number | null;
    response?: unknown;
    error?: string | null;
    pushedAt?: string | null;
  },
): Promise<void> {
  const ts = nowIso();
  await env.DB.prepare(
    `UPDATE products
       SET woo_status        = COALESCE(?, woo_status),
           woo_product_id    = COALESCE(?, woo_product_id),
           woo_queue_id      = COALESCE(?, woo_queue_id),
           woo_queue_status  = COALESCE(?, woo_queue_status),
           woo_duration_ms   = COALESCE(?, woo_duration_ms),
           woo_last_response = COALESCE(?, woo_last_response),
           woo_error         = ?,
           woo_pushed_at     = COALESCE(?, woo_pushed_at),
           woo_updated_at    = ?
     WHERE sku = ?`,
  )
    .bind(
      result.status ?? null,
      result.productId ?? null,
      result.queueId ?? null,
      result.queueStatus ?? null,
      result.durationMs ?? null,
      result.response !== undefined ? JSON.stringify(result.response) : null,
      result.error ?? null,
      result.pushedAt ?? null,
      ts,
      sku,
    )
    .run();
}

/** Products handed to the bridge whose WP-side job has not reached a terminal state. */
export async function listWooQueuePending(
  env: Env,
  limit: number,
): Promise<Array<{ sku: string; woo_queue_id: number }>> {
  const res = await env.DB.prepare(
    `SELECT sku, woo_queue_id FROM products
       WHERE woo_queue_id IS NOT NULL
         AND (woo_queue_status IS NULL OR woo_queue_status NOT IN ('completed','passed','failed'))
       ORDER BY woo_pushed_at ASC
       LIMIT ?`,
  )
    .bind(limit)
    .all<{ sku: string; woo_queue_id: number }>();
  return res.results ?? [];
}

/**
 * Retention: drop finished queue rows. Deletes in bounded chunks so a single
 * cron tick can never blow the D1 statement budget — it stops early once a
 * chunk comes back short, and gives up after `maxChunks` either way.
 */
export async function purgeTerminalSyncRows(
  env: Env,
  opts: { olderThanHours?: number; chunkSize?: number; maxChunks?: number } = {},
): Promise<number> {
  const olderThanHours = opts.olderThanHours ?? 24;
  const chunkSize = Math.min(Math.max(opts.chunkSize ?? 1000, 1), 1000);
  const maxChunks = Math.max(opts.maxChunks ?? 5, 1);
  const cutoff = new Date(Date.now() - olderThanHours * 3600000).toISOString();
  let removed = 0;
  for (let i = 0; i < maxChunks; i++) {
    const res = await env.DB.prepare(
      `DELETE FROM sync_queue
        WHERE id IN (
          SELECT id FROM sync_queue
           WHERE status IN ('done','dead')
             AND (finished_at IS NULL OR finished_at < ?)
           LIMIT ?
        )`,
    )
      .bind(cutoff, chunkSize)
      .run();
    const n = res.meta?.changes ?? 0;
    removed += n;
    if (n < chunkSize) break;
  }
  return removed;
}

/** Retention: same bounded-chunk strategy for the event log. */
export async function purgeOldSyncEvents(
  env: Env,
  opts: { olderThanDays?: number; chunkSize?: number; maxChunks?: number } = {},
): Promise<number> {
  const olderThanDays = opts.olderThanDays ?? 14;
  const chunkSize = Math.min(Math.max(opts.chunkSize ?? 1000, 1), 1000);
  const maxChunks = Math.max(opts.maxChunks ?? 5, 1);
  const cutoff = new Date(Date.now() - olderThanDays * 86400000).toISOString();
  let removed = 0;
  for (let i = 0; i < maxChunks; i++) {
    const res = await env.DB.prepare(
      `DELETE FROM sync_events
        WHERE id IN (SELECT id FROM sync_events WHERE created_at < ? LIMIT ?)`,
    )
      .bind(cutoff, chunkSize)
      .run();
    const n = res.meta?.changes ?? 0;
    removed += n;
    if (n < chunkSize) break;
  }
  return removed;
}

export async function getProductBySku(env: Env, sku: string): Promise<ProductRow | null> {
  return await env.DB.prepare("SELECT * FROM products WHERE sku = ?").bind(sku).first<ProductRow>();
}

export async function getProductBySlug(env: Env, slug: string): Promise<ProductRow | null> {
  return await env.DB.prepare("SELECT * FROM products WHERE slug = ?").bind(slug).first<ProductRow>();
}

export interface ListProductsOptions {
  limit?: number;
  offset?: number;
  scrapeStatus?: string;
  erpStatus?: string;
  wooStatus?: string;
}

export async function listProducts(env: Env, opts: ListProductsOptions = {}): Promise<{ items: ProductRow[]; total: number }> {
  const limit = Math.min(Math.max(opts.limit ?? 50, 1), 200);
  const offset = Math.max(opts.offset ?? 0, 0);

  const where: string[] = [];
  const binds: unknown[] = [];
  if (opts.scrapeStatus) {
    where.push("scrape_status = ?");
    binds.push(opts.scrapeStatus);
  }
  if (opts.erpStatus) {
    where.push("erp_status = ?");
    binds.push(opts.erpStatus);
  }
  if (opts.wooStatus) {
    where.push("woo_status = ?");
    binds.push(opts.wooStatus);
  }
  const whereSql = where.length ? `WHERE ${where.join(" AND ")}` : "";

  const totalRow = await env.DB.prepare(`SELECT COUNT(*) AS cnt FROM products ${whereSql}`)
    .bind(...binds)
    .first<{ cnt: number }>();
  const total = totalRow?.cnt ?? 0;

  const items = await env.DB.prepare(
    `SELECT * FROM products ${whereSql} ORDER BY created_at DESC LIMIT ? OFFSET ?`,
  )
    .bind(...binds, limit, offset)
    .all<ProductRow>();

  return { items: items.results, total };
}

// ---- sync_queue helpers ----

export async function enqueueSyncRow(
  env: Env,
  row: { stage: string; sku?: string | null; slug?: string | null; url?: string | null; payload?: unknown },
): Promise<number> {
  const payload = row.payload ? JSON.stringify(row.payload) : null;

  // uniq_sync_queue_active é um índice PARCIAL em (sku, stage) para as linhas
  // ativas. Reenfileirar um estágio que já tem linha ativa estourava
  // "UNIQUE constraint failed" e derrubava o rebuild inteiro. Revive a linha
  // que já existe em vez de tentar criar outra.
  const existing = await env.DB.prepare(
    `SELECT id FROM sync_queue
      WHERE stage = ? AND COALESCE(sku, '') = COALESCE(?, '')
        AND status IN ('pending','processing','failed')
      ORDER BY id ASC LIMIT 1`,
  )
    .bind(row.stage, row.sku ?? null)
    .first<{ id: number }>();

  if (existing?.id) {
    await env.DB.prepare(
      `UPDATE sync_queue
          SET status = 'pending', attempts = 0, last_error = NULL, next_retry_at = NULL,
              started_at = NULL, finished_at = NULL,
              slug = COALESCE(?, slug), url = COALESCE(?, url),
              payload_json = COALESCE(?, payload_json)
        WHERE id = ?`,
    )
      .bind(row.slug ?? null, row.url ?? null, payload, existing.id)
      .run();
    return Number(existing.id);
  }

  const result = await env.DB.prepare(
    `INSERT INTO sync_queue (sku, slug, url, stage, status, payload_json)
     VALUES (?, ?, ?, ?, 'pending', ?)`,
  )
    .bind(row.sku ?? null, row.slug ?? null, row.url ?? null, row.stage, payload)
    .run();
  return Number(result.meta?.last_row_id ?? 0);
}

export async function getSyncQueueRow(env: Env, id: number): Promise<SyncQueueRow | null> {
  return await env.DB.prepare("SELECT * FROM sync_queue WHERE id = ?").bind(id).first<SyncQueueRow>();
}

export async function markSyncRowProcessing(env: Env, id: number): Promise<void> {
  await env.DB.prepare(
    `UPDATE sync_queue
        SET status = 'processing',
            started_at = COALESCE(started_at, ?),
            attempts = attempts + 1
      WHERE id = ?`,
  )
    .bind(nowIso(), id)
    .run();
}

export async function markSyncRowDone(env: Env, id: number): Promise<void> {
  await env.DB.prepare(
    `UPDATE sync_queue SET status = 'done', finished_at = ?, last_error = NULL WHERE id = ?`,
  )
    .bind(nowIso(), id)
    .run();
}

export async function markSyncRowFailed(env: Env, id: number, err: string, dead: boolean): Promise<void> {
  await env.DB.prepare(
    `UPDATE sync_queue SET status = ?, last_error = ?, finished_at = CASE WHEN ? THEN ? ELSE finished_at END WHERE id = ?`,
  )
    .bind(dead ? "dead" : "failed", err, dead ? 1 : 0, nowIso(), id)
    .run();
}

export async function listPendingSyncRows(env: Env, limit: number): Promise<SyncQueueRow[]> {
  const result = await env.DB.prepare(
    `SELECT * FROM sync_queue
       WHERE status = 'pending'
          OR (status = 'failed' AND (next_retry_at IS NULL OR next_retry_at <= ?))
       ORDER BY id ASC
       LIMIT ?`,
  )
    .bind(nowIso(), limit)
    .all<SyncQueueRow>();
  return result.results;
}

export async function listSyncQueue(env: Env, opts: { status?: string; stage?: string; limit?: number; offset?: number } = {}): Promise<{ items: SyncQueueRow[]; total: number }> {
  const limit = Math.min(Math.max(opts.limit ?? 50, 1), 200);
  const offset = Math.max(opts.offset ?? 0, 0);
  const where: string[] = [];
  const binds: unknown[] = [];
  if (opts.status) {
    where.push("status = ?");
    binds.push(opts.status);
  }
  if (opts.stage) {
    where.push("stage = ?");
    binds.push(opts.stage);
  }
  const whereSql = where.length ? `WHERE ${where.join(" AND ")}` : "";
  const totalRow = await env.DB.prepare(`SELECT COUNT(*) AS cnt FROM sync_queue ${whereSql}`)
    .bind(...binds)
    .first<{ cnt: number }>();
  const items = await env.DB.prepare(
    `SELECT * FROM sync_queue ${whereSql} ORDER BY id DESC LIMIT ? OFFSET ?`,
  )
    .bind(...binds, limit, offset)
    .all<SyncQueueRow>();
  return { items: items.results, total: totalRow?.cnt ?? 0 };
}

export async function deleteSyncRow(env: Env, id: number): Promise<void> {
  await env.DB.prepare("DELETE FROM sync_queue WHERE id = ?").bind(id).run();
}

export async function resetSyncRowToPending(env: Env, id: number): Promise<void> {
  await env.DB.prepare(
    `UPDATE sync_queue SET status = 'pending', last_error = NULL, next_retry_at = NULL WHERE id = ?`,
  )
    .bind(id)
    .run();
}

export async function setNextRetryAt(env: Env, id: number, isoTs: string, err: string): Promise<void> {
  await env.DB.prepare(
    `UPDATE sync_queue SET status = 'failed', last_error = ?, next_retry_at = ? WHERE id = ?`,
  )
    .bind(err, isoTs, id)
    .run();
}

// ---- sync_events ----

export async function recordSyncEvent(
  env: Env,
  evt: { sku?: string | null; stage: string; level: "info" | "warn" | "error"; message: string; context?: unknown },
): Promise<void> {
  try {
    await env.DB.prepare(
      `INSERT INTO sync_events (sku, stage, level, message, context_json) VALUES (?, ?, ?, ?, ?)`,
    )
      .bind(
        evt.sku ?? null,
        evt.stage,
        evt.level,
        evt.message,
        evt.context ? JSON.stringify(evt.context) : null,
      )
      .run();
  } catch {
    // best-effort logging — never block pipeline on logging failure
  }
}

export async function listRecentEvents(env: Env, opts: { limit?: number; sku?: string } = {}): Promise<unknown[]> {
  const limit = Math.min(Math.max(opts.limit ?? 50, 1), 500);
  const where = opts.sku ? "WHERE sku = ?" : "";
  const binds = opts.sku ? [opts.sku, limit] : [limit];
  const res = await env.DB.prepare(
    `SELECT * FROM sync_events ${where} ORDER BY id DESC LIMIT ?`,
  )
    .bind(...binds)
    .all();
  return res.results.map((r: any) => ({ ...r, context: safeJsonParse(r.context_json) }));
}

// ---- app_state ----

export async function getAppState(env: Env, key: string): Promise<string | null> {
  const row = await env.DB.prepare("SELECT value FROM app_state WHERE key = ?")
    .bind(key)
    .first<{ value: string }>();
  return row?.value ?? null;
}

export async function setAppState(env: Env, key: string, value: string): Promise<void> {
  await env.DB.prepare(
    `INSERT INTO app_state (key, value, updated_at) VALUES (?, ?, ?)
     ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at`,
  )
    .bind(key, value, nowIso())
    .run();
}
