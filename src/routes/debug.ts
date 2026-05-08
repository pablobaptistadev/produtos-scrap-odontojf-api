import type { Hono } from "hono";
import type { AppEnv } from "../env";
import { ApiError } from "../core";
import { fetchProductPage, parseProductHtml } from "../scraper/product-page";
import { mergeScrapeAndErp, type MergedProduct } from "../sync/merge";
import { fetchProductFromErp } from "../erp/client";
import { resolveSku } from "../scraper/sku-resolver";
import { mirrorProductMedia } from "../sync/media";

/**
 * Inline debug routes — useful for development and one-shot inspection of the
 * scraper output without going through the SYNC_QUEUE pipeline.
 *
 * GET /debug/scrape?url=<full-product-url>
 *   → fetches the page right now and returns the parsed JSON (no DB writes,
 *     no queue events). Subject to api-key middleware like every other route.
 *
 * POST /debug/parse  (body: raw HTML, query: ?url=<url>)
 *   → parses the supplied HTML against the supplied URL and returns the JSON.
 *     Helpful for testing the parser against a saved fixture.
 */
export function registerDebugRoutes(app: Hono<AppEnv>): void {
  app.get("/debug/scrape", async (c) => {
    const target = c.req.query("url");
    if (!target) throw new ApiError(400, "query parameter `url` is required");
    if (!/^https?:\/\//.test(target)) {
      throw new ApiError(400, "`url` must be an absolute http(s) URL");
    }
    const result = await fetchProductPage(c.env, target);
    return c.json(result);
  });

  app.post("/debug/parse", async (c) => {
    const target = c.req.query("url");
    if (!target) throw new ApiError(400, "query parameter `url` is required");
    const html = await c.req.text();
    if (!html) throw new ApiError(400, "request body must contain HTML to parse");
    return c.json(parseProductHtml(target, html));
  });

  /**
   * GET /debug/full?url=<full-product-url>
   *   Runs the complete scrape → erp → merge pipeline inline and returns:
   *     - scrape: the parsed + API-enriched product JSON
   *     - erp:    the ERP response (or {status:"skipped"} when not configured)
   *     - merged: the WooCommerce-shaped payload that runPushStage would send
   *   No DB writes, no queue events.
   */
  app.get("/debug/full", async (c) => {
    const target = c.req.query("url");
    if (!target) throw new ApiError(400, "query parameter `url` is required");
    if (!/^https?:\/\//.test(target)) {
      throw new ApiError(400, "`url` must be an absolute http(s) URL");
    }

    const scrape = await fetchProductPage(c.env, target);
    const resolved = resolveSku(scrape.slug, scrape.detected_sku);
    const skuForLookup = scrape.detected_sku ?? resolved.sku;
    const erpResult = await fetchProductFromErp(c.env, skuForLookup);
    const erpData =
      erpResult.status === "ok" ? erpResult.data : null;
    const merged = mergeScrapeAndErp({
      sku: skuForLookup,
      scrape,
      erp: erpData,
    });
    // If `mirror=1` is set, also run the media stage inline so callers can
    // verify R2 mirroring without going through the queue.
    let mediaResult = null;
    let mergedAfterMirror: MergedProduct | null = null;
    if (c.req.query("mirror") === "1") {
      const r = await mirrorProductMedia(c.env, merged);
      mediaResult = r.result;
      mergedAfterMirror = r.merged;
    }
    return c.json({
      input: { url: target, sku_used_for_erp_lookup: skuForLookup },
      scrape,
      erp: erpResult,
      merged: mergedAfterMirror ?? merged,
      media_result: mediaResult,
    });
  });
}
