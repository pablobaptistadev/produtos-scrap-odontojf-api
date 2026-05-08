import type { Hono } from "hono";
import type { AppEnv } from "../env";
import { ApiError } from "../core";
import { enqueueRebuild, enqueueStage } from "../sync/orchestrator";

/**
 * Destructive admin operations. Every mutating route requires `?confirm=1`
 * AND the WORKER_API_KEY (handled by the api-key middleware), so a stray
 * request can't accidentally drop data.
 */
export function registerAdminRoutes(app: Hono<AppEnv>): void {
  /**
   * POST /admin/wipe?confirm=1&keep=products
   *   Truncates the operational tables (sync_queue, sync_events, app_state)
   *   and optionally the products table. Also drops every object inside
   *   the MEDIA R2 bucket (if bound).
   *
   *   keep=products  → leaves the products table untouched (default behaviour
   *                    for "I want a clean queue but keep my catalogue").
   *   keep=none      → also wipes products.
   */
  app.post("/admin/wipe", async (c) => {
    if (c.req.query("confirm") !== "1") {
      throw new ApiError(400, "wipe is destructive — pass ?confirm=1 to proceed");
    }
    const keep = (c.req.query("keep") ?? "products").toLowerCase();
    const tables: string[] = ["sync_queue", "sync_events", "app_state"];
    if (keep !== "products") tables.push("products");

    const before: Record<string, number> = {};
    for (const t of tables) {
      const r = await c.env.DB.prepare(`SELECT COUNT(*) AS n FROM ${t}`).first<{ n: number }>();
      before[t] = r?.n ?? 0;
    }

    for (const t of tables) {
      await c.env.DB.prepare(`DELETE FROM ${t}`).run();
    }

    // Reset autoincrement so id sequences start at 1 again.
    await c.env.DB.prepare(`DELETE FROM sqlite_sequence WHERE name IN ('sync_queue', 'sync_events')`).run();

    // Wipe R2 (in batches; R2.list pages 1000 keys at a time).
    let r2Deleted = 0;
    if (c.env.MEDIA) {
      let cursor: string | undefined;
      do {
        const list = await c.env.MEDIA.list({ limit: 1000, cursor });
        if (list.objects.length > 0) {
          // R2.delete accepts an array of keys.
          await c.env.MEDIA.delete(list.objects.map((o) => o.key));
          r2Deleted += list.objects.length;
        }
        cursor = list.truncated ? list.cursor : undefined;
      } while (cursor);
    }

    return c.json({
      wiped: true,
      tables_truncated: tables,
      rows_deleted: before,
      r2_objects_deleted: r2Deleted,
      r2_bound: Boolean(c.env.MEDIA),
    });
  });

  /**
   * POST /admin/import-urls?confirm=1
   *   Body: text/plain, one URL per line. Inserts every URL as a fresh
   *   product row (slug as provisional sku). Idempotent via INSERT OR IGNORE.
   */
  app.post("/admin/import-urls", async (c) => {
    if (c.req.query("confirm") !== "1") {
      throw new ApiError(400, "pass ?confirm=1 to proceed");
    }
    const text = await c.req.text();
    const urls = text.split(/\r?\n/).map((s) => s.trim()).filter(Boolean);
    if (urls.length === 0) throw new ApiError(400, "request body must contain at least one URL");

    let inserted = 0;
    // Batch in chunks of 200 to stay well below D1's statement size limit.
    const BATCH = 200;
    for (let i = 0; i < urls.length; i += BATCH) {
      const slice = urls.slice(i, i + BATCH);
      const placeholders = slice.map(() => "(?, ?, ?, 1)").join(", ");
      const binds: string[] = [];
      for (const u of slice) {
        const slug = u.replace(/\/$/, "").split("/").pop() ?? "";
        binds.push(slug, slug, u);
      }
      const stmt = c.env.DB.prepare(
        `INSERT OR IGNORE INTO products (sku, slug, source_url, sku_provisional) VALUES ${placeholders}`,
      );
      const res = await stmt.bind(...binds).run();
      inserted += (res.meta as any)?.changes ?? 0;
    }
    return c.json({ submitted: urls.length, inserted });
  });

  /**
   * POST /admin/start-scrape?limit=<N>
   *   Enqueues the `scrape` stage for up to N products that have
   *   scrape_status='pending'. Use limit=0 (default) to enqueue every
   *   pending row.
   */
  app.post("/admin/start-scrape", async (c) => {
    const limit = Math.max(0, Number.parseInt(c.req.query("limit") ?? "0", 10));
    const sql =
      `SELECT sku, slug, source_url FROM products WHERE scrape_status = 'pending'`
      + (limit > 0 ? ` LIMIT ${limit}` : "");
    const res = await c.env.DB.prepare(sql).all<{ sku: string; slug: string; source_url: string }>();
    let enqueued = 0;
    for (const row of res.results ?? []) {
      try {
        await enqueueStage(c.env, { stage: "scrape", sku: row.sku, slug: row.slug, url: row.source_url });
        enqueued++;
      } catch (err) {
        // Continue on per-row failure (Queues might transiently return non-2xx).
      }
    }
    return c.json({ enqueued, total_pending: res.results?.length ?? 0 });
  });

  /**
   * POST /admin/advance?from=<stage>&to=<stage>&limit=<N>
   *   Bulk-promotes products through the pipeline by enqueuing the next
   *   stage for every product whose current stage status is "ok".
   *
   *   from/to:
   *     scrape → erp     (every product with scrape_status='ok' AND erp_status='pending')
   *     erp    → merge   (every product with erp_status IN ('ok','skipped') AND merged_json IS NULL)
   *     merge  → media   (every product with merged_json NOT NULL AND merged_json NOT LIKE '%media.%')
   *     media  → push    (every product with merged_json LIKE '%media.%' AND woo_status='pending')
   */
  app.post("/admin/advance", async (c) => {
    const from = c.req.query("from");
    const to = c.req.query("to");
    const limit = Math.max(0, Number.parseInt(c.req.query("limit") ?? "0", 10));
    if (!from || !to) throw new ApiError(400, "from and to query params are required");

    const filters: Record<string, string> = {
      "scrape>erp": `scrape_status = 'ok' AND erp_status = 'pending'`,
      "erp>merge": `erp_status IN ('ok', 'skipped') AND merged_json IS NULL`,
      "merge>media": `merged_json IS NOT NULL AND merged_json NOT LIKE '%media.odontoapi%'`,
      "media>push": `merged_json LIKE '%media.odontoapi%' AND woo_status = 'pending'`,
    };
    const where = filters[`${from}>${to}`];
    if (!where) throw new ApiError(400, `unsupported transition ${from} -> ${to}`);

    const sql =
      `SELECT sku, slug, source_url FROM products WHERE ${where}`
      + (limit > 0 ? ` LIMIT ${limit}` : "");
    const res = await c.env.DB.prepare(sql).all<{ sku: string; slug: string; source_url: string }>();
    let enqueued = 0;
    for (const row of res.results ?? []) {
      try {
        await enqueueStage(c.env, { stage: to as any, sku: row.sku, slug: row.slug, url: row.source_url });
        enqueued++;
      } catch (err) {
        /* continue */
      }
    }
    return c.json({ from, to, enqueued, candidates: res.results?.length ?? 0 });
  });
}
