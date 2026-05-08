import type { Hono } from "hono";
import type { AppEnv } from "../env";
import { ApiError, safeJsonParse } from "../core";
import { enqueueRebuild, enqueueStage } from "../sync/orchestrator";
import { getProductBySku, updateScrapeResult } from "../db/repo";
import { mirrorScrapedMedia } from "../sync/media";
import type { ScrapeResult } from "../scraper/product-page";

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

  /**
   * POST /admin/mirror-scraped?limit=<N>
   *   Backfill: for every product with scrape_status='ok' whose scrape_json
   *   still references the external CDN, fetch each image/PDF, push it to
   *   R2 and rewrite the URLs inside the saved scrape_json. Idempotent —
   *   products already on `media.odontoapi…` are skipped.
   */
  app.post("/admin/mirror-scraped", async (c) => {
    const limit = Math.max(0, Number.parseInt(c.req.query("limit") ?? "50", 10));
    if (!c.env.MEDIA || !c.env.MEDIA_PUBLIC_BASE_URL) {
      throw new ApiError(503, "R2 bucket not bound (MEDIA + MEDIA_PUBLIC_BASE_URL required)");
    }
    // Only pick products that still reference the external storefront CDN.
    // Anchor on the full host so we don't match other strings that happen to
    // contain "catalogo" (e.g. blob:https://admin.catalogomaisodonto.com.br/…).
    const sql =
      `SELECT sku, scrape_json FROM products
        WHERE scrape_status = 'ok'
          AND scrape_json IS NOT NULL
          AND scrape_json LIKE '%storage.googleapis.com/catalogo-mais-odonto%'`
      + (limit > 0 ? ` LIMIT ${limit}` : "");
    const res = await c.env.DB.prepare(sql).all<{ sku: string; scrape_json: string }>();
    let processed = 0;
    let mirrored = 0;
    let failed = 0;
    let alreadyOurs = 0;
    const skuErrors: string[] = [];

    for (const row of res.results ?? []) {
      try {
        const scrape = safeJsonParse<ScrapeResult>(row.scrape_json);
        if (!scrape) { failed++; continue; }
        const result = await mirrorScrapedMedia(c.env, scrape, scrape.detected_sku ?? row.sku);
        // Direct UPDATE so the rewritten scrape_json is the only thing we touch
        // (avoids any overwrite path inside the helper functions).
        await c.env.DB.prepare(
          `UPDATE products SET scrape_json = ?, scrape_updated_at = ? WHERE sku = ?`,
        ).bind(JSON.stringify(scrape), new Date().toISOString(), row.sku).run();
        processed++;
        mirrored += result.result.mirrored;
        failed += result.result.failed;
        alreadyOurs += result.result.alreadyOurs;
      } catch (err) {
        failed++;
        skuErrors.push(`${row.sku}: ${err instanceof Error ? err.message : String(err)}`);
      }
    }
    return c.json({
      candidates: res.results?.length ?? 0,
      processed,
      files_mirrored: mirrored,
      files_already_in_r2: alreadyOurs,
      files_failed: failed,
      sku_errors: skuErrors.slice(0, 10),
    });
  });

  /** Diagnostic: show scrape_json before+after a mirror for a single SKU. */
  app.get("/admin/debug-mirror", async (c) => {
    const sku = c.req.query("sku");
    if (!sku) throw new ApiError(400, "sku required");
    const product = await getProductBySku(c.env, sku);
    if (!product) throw new ApiError(404, "not found");
    const scrape = safeJsonParse<ScrapeResult>(product.scrape_json ?? "");
    if (!scrape) throw new ApiError(404, "no scrape data");
    const beforeImages = JSON.parse(JSON.stringify(scrape.images ?? []));
    const r = await mirrorScrapedMedia(c.env, scrape, scrape.detected_sku ?? sku);
    let persistedHasMedia = false;
    if (c.req.query("save") === "1") {
      // Direct write — bypass updateScrapeResult to isolate any helper issue.
      const stringified = JSON.stringify(scrape);
      await c.env.DB.prepare(
        `UPDATE products SET scrape_json = ?, scrape_updated_at = ? WHERE sku = ?`,
      ).bind(stringified, new Date().toISOString(), sku).run();
      // Read back to confirm.
      const after = await c.env.DB.prepare(`SELECT scrape_json FROM products WHERE sku = ?`).bind(sku).first<{ scrape_json: string }>();
      persistedHasMedia = (after?.scrape_json ?? "").includes("media.odontoapi");
    }
    return c.json({
      sku,
      detected_sku: scrape.detected_sku,
      result: r.result,
      before_images: beforeImages,
      after_images: scrape.images,
      persisted_has_media: persistedHasMedia,
      stringified_has_media: JSON.stringify(scrape).includes("media.odontoapi"),
    });
  });
}
