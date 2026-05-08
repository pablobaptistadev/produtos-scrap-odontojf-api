import type { Env, SyncQueueMessage } from "../env";
import {
  enqueueSyncRow,
  upsertProductFromSitemap,
  recordSyncEvent,
  getProductBySku,
  setAppState,
  getAppState,
  listPendingSyncRows,
} from "../db/repo";
import { fetchProductSitemap } from "../scraper/sitemap";
import { fetchProductPage } from "../scraper/product-page";
import { resolveSku } from "../scraper/sku-resolver";
import { fetchProductFromErp } from "../erp/client";
import { mergeScrapeAndErp, type MergedProduct } from "./merge";
import { mirrorProductMedia } from "./media";
import { upsertWooProduct } from "../woo/client";
import {
  updateScrapeResult,
  updateErpResult,
  updateMergedResult,
  updateWooResult,
} from "../db/repo";
import { safeJsonParse } from "../core";

export const STAGES: SyncQueueMessage["stage"][] = ["rebuild", "scrape", "erp", "merge", "media", "push"];

export async function enqueueRebuild(env: Env, opts: { reason?: string } = {}): Promise<number> {
  const id = await enqueueSyncRow(env, { stage: "rebuild", payload: opts });
  await env.SYNC_QUEUE.send({ stage: "rebuild", queue_row_id: id });
  await recordSyncEvent(env, { stage: "rebuild", level: "info", message: "rebuild enqueued", context: opts });
  return id;
}

export async function enqueueStage(
  env: Env,
  opts: { stage: Exclude<SyncQueueMessage["stage"], "rebuild">; sku: string; slug?: string | null; url?: string | null },
): Promise<number> {
  const id = await enqueueSyncRow(env, { stage: opts.stage, sku: opts.sku, slug: opts.slug ?? null, url: opts.url ?? null });
  await env.SYNC_QUEUE.send({ stage: opts.stage, sku: opts.sku, slug: opts.slug ?? null, url: opts.url ?? null, queue_row_id: id });
  return id;
}

// ---- stage runners ----

export async function runRebuildStage(env: Env): Promise<void> {
  // Mark start IMMEDIATELY so cron doesn't keep re-firing rebuild on partial failure.
  await setAppState(env, "last_rebuild_at", new Date().toISOString());
  const entries = await fetchProductSitemap(env);
  let inserted = 0;
  let skipped = 0;
  for (const entry of entries) {
    const resolved = resolveSku(entry.slug, null);
    try {
      await upsertProductFromSitemap(env, {
        sku: resolved.sku,
        slug: entry.slug,
        sourceUrl: entry.loc,
        provisional: resolved.provisional,
      });
      await enqueueStage(env, { stage: "scrape", sku: resolved.sku, slug: entry.slug, url: entry.loc });
      inserted++;
    } catch (err) {
      // Skip entries that hit constraint conflicts (e.g. shared trailing code → same SKU
      // between two sitemap entries). Don't abort the whole rebuild.
      skipped++;
      await recordSyncEvent(env, {
        sku: resolved.sku,
        stage: "rebuild",
        level: "warn",
        message: `skipped sitemap entry: ${err instanceof Error ? err.message : String(err)}`,
        context: { slug: entry.slug },
      });
    }
  }
  await setAppState(env, "last_rebuild_count", String(inserted));
  await recordSyncEvent(env, {
    stage: "rebuild",
    level: "info",
    message: `rebuild: ${entries.length} entries, ${inserted} enqueued, ${skipped} skipped`,
  });
}

export async function runScrapeStage(env: Env, sku: string, url: string): Promise<void> {
  try {
    const scrape = await fetchProductPage(env, url);
    const externalSku = scrape.detected_sku && scrape.detected_sku !== sku ? scrape.detected_sku : null;
    await updateScrapeResult(env, sku, { status: "ok", json: scrape, externalSku });
    await enqueueStage(env, { stage: "erp", sku, slug: scrape.slug, url: scrape.url });
  } catch (err) {
    const msg = err instanceof Error ? err.message : String(err);
    await updateScrapeResult(env, sku, { status: "failed", error: msg });
    await recordSyncEvent(env, { sku, stage: "scrape", level: "error", message: msg });
    throw err;
  }
}

export async function runErpStage(env: Env, sku: string): Promise<void> {
  const product = await getProductBySku(env, sku);
  const lookupSku = product?.external_sku ?? sku;
  const result = await fetchProductFromErp(env, lookupSku);
  if (result.status === "skipped") {
    await updateErpResult(env, sku, { status: "skipped", error: result.reason });
    await recordSyncEvent(env, { sku, stage: "erp", level: "warn", message: result.reason });
    // even when skipped, advance to merge so scrape data still propagates to woo (best effort)
    await enqueueStage(env, { stage: "merge", sku });
    return;
  }
  if (result.status === "failed") {
    await updateErpResult(env, sku, { status: "failed", error: result.reason });
    throw new Error(`erp fetch failed: ${result.reason}`);
  }
  await updateErpResult(env, sku, { status: "ok", json: result.data });
  await enqueueStage(env, { stage: "merge", sku });
}

export async function runMergeStage(env: Env, sku: string): Promise<void> {
  const product = await getProductBySku(env, sku);
  if (!product) throw new Error(`product not found for sku=${sku}`);
  const scrape = product.scrape_json ? safeJsonParse(product.scrape_json) : null;
  const erp = product.erp_json ? safeJsonParse(product.erp_json) : null;
  const effectiveSku = product.external_sku ?? sku;
  const merged = mergeScrapeAndErp({ sku: effectiveSku, scrape: scrape as any, erp });
  await updateMergedResult(env, sku, merged);
  // Always queue the media stage so URLs get mirrored to R2 before any Woo
  // write. If the bucket isn't bound the stage no-ops gracefully.
  await enqueueStage(env, { stage: "media", sku });
}

export async function runMediaStage(env: Env, sku: string): Promise<void> {
  const product = await getProductBySku(env, sku);
  if (!product) throw new Error(`product not found for sku=${sku}`);
  if (!product.merged_json) throw new Error(`merged payload missing for sku=${sku}`);
  const merged = safeJsonParse<MergedProduct>(product.merged_json);
  if (!merged) throw new Error(`merged payload invalid JSON for sku=${sku}`);

  const { merged: mirrored, result } = await mirrorProductMedia(env, merged);
  // Persist the rewritten merged_json back to D1.
  await updateMergedResult(env, sku, mirrored);

  await recordSyncEvent(env, {
    sku,
    stage: "media",
    level: result.failed > 0 ? "warn" : "info",
    message: `media: attempted=${result.attempted} mirrored=${result.mirrored} alreadyOurs=${result.alreadyOurs} failed=${result.failed}`,
    context: result,
  });

  const pushEnabled = String(env.WOO_PUSH_ENABLED ?? "").toLowerCase();
  if (pushEnabled === "1" || pushEnabled === "true" || pushEnabled === "yes") {
    await enqueueStage(env, { stage: "push", sku });
  } else {
    await recordSyncEvent(env, {
      sku,
      stage: "media",
      level: "info",
      message: "media ok — push skipped (WOO_PUSH_ENABLED is off)",
    });
  }
}

export async function runPushStage(env: Env, sku: string): Promise<void> {
  const product = await getProductBySku(env, sku);
  if (!product) throw new Error(`product not found for sku=${sku}`);
  if (!product.merged_json) throw new Error(`merged payload missing for sku=${sku}`);
  const merged = safeJsonParse<Record<string, unknown>>(product.merged_json);
  if (!merged) throw new Error(`merged payload invalid JSON for sku=${sku}`);
  const wooSku = product.external_sku ?? sku;
  const result = await upsertWooProduct(env, {
    sku: wooSku,
    merged,
    existingId: product.woo_product_id,
  });
  if (result.status === "skipped") {
    await updateWooResult(env, sku, { status: "skipped", error: result.reason });
    await recordSyncEvent(env, { sku, stage: "push", level: "warn", message: result.reason });
    return;
  }
  if (result.status === "failed") {
    await updateWooResult(env, sku, { status: "failed", error: result.reason, response: result.response });
    throw new Error(`woo push failed: ${result.reason}`);
  }
  await updateWooResult(env, sku, { status: "ok", productId: result.productId, response: result.response });
}

// ---- cron helpers ----

export async function shouldRebuild(env: Env): Promise<boolean> {
  const last = await getAppState(env, "last_rebuild_at");
  if (!last) return true;
  const intervalHours = Number.parseInt(env.REBUILD_INTERVAL_HOURS ?? "24", 10);
  const ageMs = Date.now() - new Date(last).getTime();
  return ageMs > intervalHours * 3600 * 1000;
}

export async function drainPendingToQueue(env: Env, limit: number): Promise<number> {
  const rows = await listPendingSyncRows(env, limit);
  let dispatched = 0;
  for (const row of rows) {
    await env.SYNC_QUEUE.send({
      stage: row.stage as SyncQueueMessage["stage"],
      sku: row.sku,
      slug: row.slug,
      url: row.url,
      queue_row_id: row.id,
    });
    dispatched++;
  }
  return dispatched;
}
