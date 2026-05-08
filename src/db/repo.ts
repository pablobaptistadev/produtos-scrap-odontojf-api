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
  result: { status: "ok" | "failed"; json?: unknown; error?: string; externalSku?: string | null },
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
  const result = await env.DB.prepare(
    `INSERT INTO sync_queue (sku, slug, url, stage, status, payload_json)
     VALUES (?, ?, ?, ?, 'pending', ?)`,
  )
    .bind(
      row.sku ?? null,
      row.slug ?? null,
      row.url ?? null,
      row.stage,
      row.payload ? JSON.stringify(row.payload) : null,
    )
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
