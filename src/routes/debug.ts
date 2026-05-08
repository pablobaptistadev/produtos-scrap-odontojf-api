import type { Hono } from "hono";
import type { AppEnv } from "../env";
import { ApiError } from "../core";
import { fetchProductPage, parseProductHtml } from "../scraper/product-page";
import { mergeScrapeAndErp, type MergedProduct } from "../sync/merge";
import { fetchProductFromErp } from "../erp/client";
import { resolveSku } from "../scraper/sku-resolver";
import { mirrorProductMedia } from "../sync/media";
import { getProductBySku, getProductBySlug, updateScrapeResult, updateErpResult, updateMergedResult } from "../db/repo";

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

  /**
   * POST /debug/run-pipeline?sku=<sku>&mirror=1
   *   Runs scrape → erp → merge (and optionally media) inline AND persists
   *   each stage's output to D1, just like the queue worker would. Lets you
   *   land a product on a fresh deploy without waiting on the queue.
   *
   *   - sku: existing row in `products` (slug or numeric SKU). If the row's
   *     external_sku changes after scraping (e.g. slug → real internalId),
   *     persist updates against the original row keyed by `sku`.
   */
  app.post("/debug/run-pipeline", async (c) => {
    const sku = c.req.query("sku");
    if (!sku) throw new ApiError(400, "query parameter `sku` is required");
    const product = await getProductBySku(c.env, sku) ?? await getProductBySlug(c.env, sku);
    if (!product) throw new ApiError(404, `product not found: ${sku}`);

    // 1. SCRAPE
    const scrape = await fetchProductPage(c.env, product.source_url);
    const externalSku = scrape.detected_sku && scrape.detected_sku !== product.sku ? scrape.detected_sku : null;
    await updateScrapeResult(c.env, product.sku, { status: "ok", json: scrape, externalSku });

    // 2. ERP (best-effort; "skipped" is fine)
    const lookupSku = externalSku ?? product.external_sku ?? product.sku;
    const erpResult = await fetchProductFromErp(c.env, lookupSku);
    if (erpResult.status === "ok") {
      await updateErpResult(c.env, product.sku, { status: "ok", json: erpResult.data });
    } else {
      await updateErpResult(c.env, product.sku, { status: erpResult.status, error: (erpResult as any).reason });
    }

    // 3. MERGE
    const erpData = erpResult.status === "ok" ? erpResult.data : null;
    const merged = mergeScrapeAndErp({ sku: lookupSku, scrape, erp: erpData });

    // 4. MEDIA (optional)
    let mediaResult = null;
    let finalMerged: MergedProduct = merged;
    if (c.req.query("mirror") === "1") {
      const r = await mirrorProductMedia(c.env, merged);
      mediaResult = r.result;
      finalMerged = r.merged;
    }

    await updateMergedResult(c.env, product.sku, finalMerged);

    return c.json({
      sku: product.sku,
      external_sku: externalSku ?? product.external_sku,
      stages: {
        scrape: { status: "ok", detected_sku: scrape.detected_sku, type: scrape.type, variations: scrape.variations.length },
        erp: erpResult,
        merge: { sku: finalMerged.sku, type: finalMerged.type, regular_price: finalMerged.regular_price, variations: finalMerged.variations.length, warnings: finalMerged.warnings },
        media: mediaResult,
      },
      preview_url: `/dashboard/product/${encodeURIComponent(product.sku)}?key=...`,
    });
  });
}
