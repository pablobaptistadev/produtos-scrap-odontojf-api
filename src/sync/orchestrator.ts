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
import { mirrorProductMedia, mirrorScrapedMedia } from "./media";
import { upsertWooProduct } from "../woo/client";
import { pushProductToPlugin, pollPluginQueue, pluginStatusToWoo } from "../woo/plugin-client";
import {
  updateScrapeResult,
  updateErpResult,
  updateMergedResult,
  updateWooResult,
  updateWooQueueResult,
  listWooQueuePending,
} from "../db/repo";
import { safeJsonParse, parseIntEnv } from "../core";

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

function isFlagOn(value: string | undefined): boolean {
  const v = String(value ?? "").toLowerCase();
  return v === "1" || v === "true" || v === "yes";
}

export async function runScrapeStage(env: Env, sku: string, url: string): Promise<void> {
  try {
    const scrape = await fetchProductPage(env, url);

    // Anti-duplication guard. On the origin every variation is ALSO a
    // standalone page (`type: "familyProduct"`), and its code is the same one
    // that already lives on a variation of the family parent here. Ingesting
    // one would create a simple product whose SKU collides with that variation
    // — WooCommerce enforces SKU uniqueness across products AND variations.
    //
    // Today the sitemap only publishes "family" and "singleProduct", so this
    // never fires; it exists so a sitemap change or a manual
    // POST /admin/import-urls cannot silently corrupt the catalogue.
    if (scrape.origin_type === "familyProduct") {
      const reason = `skipped: origin type is familyProduct (a variation of another product, code=${scrape.detected_sku ?? "?"}) — it must not become a standalone product`;
      await updateScrapeResult(env, sku, { status: "skipped", error: reason });
      await recordSyncEvent(env, {
        sku,
        stage: "scrape",
        level: "warn",
        message: reason,
        context: { url, detected_sku: scrape.detected_sku },
      });
      return;
    }

    const externalSku = scrape.detected_sku && scrape.detected_sku !== sku ? scrape.detected_sku : null;

    // Mirror media to R2 INSIDE the scrape stage. Strict policy: if any single
    // file can't be copied to R2, fail the whole scrape stage so the row stays
    // out of D1 (with `scrape_status='failed'`) and the queue retries — never
    // persist external storefront URLs that may be rotated later.
    // Override with SCRAPE_AUTO_MIRROR=0 only for non-prod debugging.
    const mirrorOff = String(env.SCRAPE_AUTO_MIRROR ?? "").toLowerCase() === "0";
    if (!mirrorOff) {
      if (!env.MEDIA || !env.MEDIA_PUBLIC_BASE_URL) {
        throw new Error(
          "media stage required but MEDIA/MEDIA_PUBLIC_BASE_URL not configured — refusing to persist external CDN URLs",
        );
      }
      const { result } = await mirrorScrapedMedia(env, scrape, externalSku ?? sku);
      // Only transient failures (5xx / network) trigger a scrape retry.
      // Permanent failures (4xx) mean the storefront rotated/removed those
      // specific assets — retrying the scrape won't bring them back, so we
      // accept the partial mirror and move on. Source URLs that 4xx end up
      // null'd out of scrape_json by the mirror() helper.
      if (result.failed > 0) {
        await recordSyncEvent(env, {
          sku,
          stage: "scrape",
          level: "error",
          message: `mirror transient failure ${result.failed}/${result.attempted}; retrying scrape`,
          context: result,
        });
        throw new Error(`mirror transient failure ${result.failed}/${result.attempted} file(s)`);
      }
      if (result.permanent_failed > 0) {
        await recordSyncEvent(env, {
          sku,
          stage: "scrape",
          level: "warn",
          message: `${result.permanent_failed}/${result.attempted} source files were rotated by the storefront (4xx); accepted partial mirror`,
          context: result,
        });
      }
    }

    // Persist the (possibly rewritten) scrape_json directly. The helper
    // updateScrapeResult was observed not to commit the JSON column reliably
    // when called twice in a row on the same row, so we write it via a single
    // explicit UPDATE.
    const ts = new Date().toISOString();
    await env.DB.prepare(
      `UPDATE products
         SET scrape_json = ?,
             scrape_status = 'ok',
             scrape_updated_at = ?,
             scrape_error = NULL,
             external_sku = COALESCE(?, external_sku)
       WHERE sku = ?`,
    ).bind(JSON.stringify(scrape), ts, externalSku ?? null, sku).run();
    if (isFlagOn(env.AUTO_ENQUEUE_ERP)) {
      await enqueueStage(env, { stage: "erp", sku, slug: scrape.slug, url: scrape.url });
    }
  } catch (err) {
    const msg = err instanceof Error ? err.message : String(err);
    await updateScrapeResult(env, sku, { status: "failed", error: msg });
    await recordSyncEvent(env, { sku, stage: "scrape", level: "error", message: msg });
    throw err;
  }
}

const ERP_DOWN_KEY = "erp_down_until";

/** O ERP está em janela de "fora do ar"? */
async function erpIsDown(env: Env): Promise<boolean> {
  const until = await getAppState(env, ERP_DOWN_KEY);
  if (!until) return false;
  return Date.parse(until) > Date.now();
}

async function erpMarkDown(env: Env): Promise<void> {
  const ttl = parseIntEnv(env.ERP_DOWN_TTL_SEC, 300);
  await setAppState(env, ERP_DOWN_KEY, new Date(Date.now() + ttl * 1000).toISOString());
}

async function erpMarkUp(env: Env): Promise<void> {
  const until = await getAppState(env, ERP_DOWN_KEY);
  if (until) await setAppState(env, ERP_DOWN_KEY, "");
}

/** Falha de rede (timeout/conexão) — o ERP inteiro está fora, não é este SKU. */
function looksLikeErpOutage(reason: string): boolean {
  return /timeout|timed out|econn|network|socket|refused|unreach|closed/i.test(reason);
}

/** O produto pode seguir o pipeline sem o ERP? */
function erpIsOptional(env: Env): boolean {
  if (isFlagOn(env.ERP_OPTIONAL)) return true;
  // Com o preço vindo da loja, o ERP não acrescenta nada ao push.
  return (env.WOO_PUSH_PRICING ?? "erp").toLowerCase() === "store";
}

export async function runErpStage(env: Env, sku: string): Promise<void> {
  const product = await getProductBySku(env, sku);
  const lookupSku = product?.external_sku ?? sku;
  const optional = erpIsOptional(env);

  const seguir = async () => {
    if (isFlagOn(env.AUTO_ENQUEUE_MERGE)) {
      await enqueueStage(env, { stage: "merge", sku });
    }
  };

  // DISJUNTOR. Com o ERP fora, cada linha pagava o timeout inteiro e o tick do
  // cron acabava antes de drenar qualquer coisa: a fila parava de andar mesmo
  // com scrape e merge saudáveis. Dentro da janela, nem tenta.
  if (await erpIsDown(env)) {
    const reason = "ERP fora do ar (disjuntor aberto) — produto segue sem dado do ERP";
    await updateErpResult(env, sku, { status: "skipped", error: reason });
    await recordSyncEvent(env, { sku, stage: "erp", level: "warn", message: reason });
    await seguir();
    return;
  }

  const result = await fetchProductFromErp(env, lookupSku);
  if (result.status === "skipped") {
    await updateErpResult(env, sku, { status: "skipped", error: result.reason });
    await recordSyncEvent(env, { sku, stage: "erp", level: "warn", message: result.reason });
    await seguir();
    return;
  }
  if (result.status === "failed") {
    await updateErpResult(env, sku, { status: "failed", error: result.reason });
    if (looksLikeErpOutage(result.reason)) {
      await erpMarkDown(env);
      await recordSyncEvent(env, {
        sku,
        stage: "erp",
        level: "error",
        message: `ERP indisponível (${result.reason}); disjuntor aberto por ${parseIntEnv(env.ERP_DOWN_TTL_SEC, 300)}s`,
      });
    }
    // Sem o ERP o produto ainda tem título, descrição e galeria da origem para
    // publicar. Travá-lo aqui é o que deixou ~2.000 produtos parados.
    if (optional) {
      await seguir();
      return;
    }
    throw new Error(`erp fetch failed: ${result.reason}`);
  }
  await erpMarkUp(env);
  await updateErpResult(env, sku, { status: "ok", json: result.data });
  await seguir();
}

export async function runMergeStage(env: Env, sku: string): Promise<void> {
  const product = await getProductBySku(env, sku);
  if (!product) throw new Error(`product not found for sku=${sku}`);
  const scrape = product.scrape_json ? safeJsonParse(product.scrape_json) : null;
  const erp = product.erp_json ? safeJsonParse(product.erp_json) : null;
  const effectiveSku = product.external_sku ?? sku;
  const merged = mergeScrapeAndErp({ sku: effectiveSku, scrape: scrape as any, erp });
  await updateMergedResult(env, sku, merged);
  if (isFlagOn(env.AUTO_ENQUEUE_MEDIA)) {
    await enqueueStage(env, { stage: "media", sku });
  }
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

  if (isFlagOn(env.WOO_PUSH_ENABLED)) {
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

  // Never publish a product whose scrape is not clean — the merged payload
  // would carry stale or partial origin data.
  if (product.scrape_status !== "ok") {
    const reason = `scrape not ok (status=${product.scrape_status ?? "unknown"})`;
    await updateWooResult(env, sku, { status: "skipped", error: reason });
    await recordSyncEvent(env, { sku, stage: "push", level: "warn", message: reason });
    return;
  }
  // Without ERP the price/stock would be wrong, so skip by default. The
  // exception is WOO_PUSH_PRICING=store, where the payload carries no price at
  // all and WooCommerce keeps its own — there is then nothing for missing ERP
  // data to get wrong, and content can be published while the ERP is down.
  const pricingFromStore = (env.WOO_PUSH_PRICING ?? "erp").toLowerCase() === "store";
  if (product.erp_status === "failed" && !pricingFromStore && !isFlagOn(env.WOO_PUSH_INCLUDE_ERP_FAILED)) {
    const reason = "erp data missing — skipped (set WOO_PUSH_INCLUDE_ERP_FAILED=1 to push anyway)";
    await updateWooResult(env, sku, { status: "skipped", error: reason });
    await recordSyncEvent(env, { sku, stage: "push", level: "warn", message: reason });
    return;
  }

  // Nada mudou desde o último push bem-sucedido? Não empurra.
  //
  // O rebuild reprocessa o catálogo inteiro em ciclo, e sem esta guarda cada
  // volta reenfileirava ~3.600 produtos no WordPress mesmo com o conteúdo
  // idêntico. A fila de lá dá conta de ~2,5 jobs/min (produto variável com
  // dezenas de variações e upload de imagem), então o backlog só crescia —
  // 711 -> 1.461 numa tarde — e foi esse desequilíbrio que derrubou o banco
  // da loja. WOO_PUSH_FORCE=1 ignora a guarda quando é preciso reempurrar tudo.
  const jaPublicado = product.woo_status === "ok" && !!product.woo_pushed_at;
  const mudouDesdePush =
    !product.merged_updated_at ||
    !product.woo_pushed_at ||
    Date.parse(product.merged_updated_at) > Date.parse(product.woo_pushed_at);
  if (jaPublicado && !mudouDesdePush && !isFlagOn(env.WOO_PUSH_FORCE)) {
    await recordSyncEvent(env, {
      sku,
      stage: "push",
      level: "info",
      message: "sem mudança desde o último push — pulado",
      context: { merged_updated_at: product.merged_updated_at, woo_pushed_at: product.woo_pushed_at },
    });
    return;
  }

  const merged = safeJsonParse<Record<string, unknown>>(product.merged_json);
  if (!merged) throw new Error(`merged payload invalid JSON for sku=${sku}`);
  const wooSku = product.external_sku ?? sku;

  const mode = (env.WOO_PUSH_MODE ?? "plugin").toLowerCase();
  if (mode === "wcrest") {
    await runPushViaWcRest(env, sku, wooSku, merged, product.woo_product_id);
    return;
  }
  await runPushViaPlugin(env, sku, wooSku, merged, product.merged_updated_at, product.woo_product_id);
}

/**
 * Default path: hand the product to the OdontoJF Woo Bridge plugin, which
 * answers with a queue receipt and writes to WooCommerce asynchronously.
 * The row therefore lands as `processing` + `woo_queue_id`; the terminal state
 * arrives either from the optional poll loop below or from a later reconcile.
 */
async function runPushViaPlugin(
  env: Env,
  sku: string,
  wooSku: string,
  merged: Record<string, unknown>,
  mergedUpdatedAt: string | null,
  existingWooId: number | null,
): Promise<void> {
  const r = await pushProductToPlugin(env, {
    sku: wooSku,
    merged,
    mergedUpdatedAt,
    preferUpdate: existingWooId != null,
  });

  if (r.status === "skipped") {
    await updateWooQueueResult(env, sku, { status: "skipped", error: r.reason });
    await recordSyncEvent(env, { sku, stage: "push", level: "warn", message: r.reason ?? "skipped" });
    return;
  }
  if (r.status === "failed") {
    await updateWooQueueResult(env, sku, { status: "failed", error: r.reason, response: r.response });
    throw new Error(`woo plugin push failed: ${r.reason}`);
  }

  const nowTs = new Date().toISOString();
  await updateWooQueueResult(env, sku, {
    status: "processing",
    queueId: r.queueId ?? null,
    queueStatus: "pending",
    pushedAt: nowTs,
    response: r.response,
    error: null,
  });
  await recordSyncEvent(env, {
    sku,
    stage: "push",
    level: "info",
    message: `woo enqueued on WP (queue_id=${r.queueId ?? "?"})`,
    context: { queueId: r.queueId },
  });

  const pollMax = parseIntEnv(env.WOO_PLUGIN_POLL_MAX, 0);
  if (pollMax > 0 && r.queueId) {
    for (let i = 0; i < pollMax; i++) {
      const st = await pollPluginQueue(env, r.queueId);
      if (st && (st.status === "completed" || st.status === "passed" || st.status === "failed")) {
        await updateWooQueueResult(env, sku, {
          status: pluginStatusToWoo(st.status),
          queueStatus: st.status,
          productId: st.productId,
          durationMs: st.durationMs,
          error: st.status === "failed" ? pluginQueueError(st.error) : null,
        });
        return;
      }
    }
  }
}

/** O erro da fila do plugin pode vir como objeto (WP_Error serializado). Achata
 *  para string legível — é ele que vira a lista de revisão das recusas 409. */
function pluginQueueError(err: unknown): string {
  if (typeof err === "string" && err.trim() !== "") return err;
  if (err && typeof err === "object") {
    const o = err as Record<string, unknown>;
    const code = typeof o.code === "string" ? o.code : "";
    const msg = typeof o.message === "string" ? o.message : "";
    if (code || msg) return code && msg ? `${code}: ${msg}` : code || msg;
    try {
      return JSON.stringify(err).slice(0, 500);
    } catch {
      /* ignore */
    }
  }
  return "WP handler failed";
}

/** Legacy path (`WOO_PUSH_MODE=wcrest`): write straight to core WooCommerce. */
async function runPushViaWcRest(
  env: Env,
  sku: string,
  wooSku: string,
  merged: Record<string, unknown>,
  existingWooId: number | null,
): Promise<void> {
  const result = await upsertWooProduct(env, { sku: wooSku, merged, existingId: existingWooId });
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

  if (result.variations) {
    const v = result.variations;
    await recordSyncEvent(env, {
      sku,
      stage: "push",
      level: v.failed > 0 ? "warn" : "info",
      message: `woo push ok (parentId=${result.productId} created=${result.created}; variations: created=${v.created} updated=${v.updated} deleted=${v.deleted} failed=${v.failed})`,
      context: { productId: result.productId, variations: v },
    });
  } else {
    await recordSyncEvent(env, {
      sku,
      stage: "push",
      level: "info",
      message: `woo push ok (parentId=${result.productId} created=${result.created})`,
      context: { productId: result.productId },
    });
  }
}

// ---- cron helpers ----

export async function shouldRebuild(env: Env): Promise<boolean> {
  const last = await getAppState(env, "last_rebuild_at");
  if (!last) return true;
  const intervalHours = Number.parseInt(env.REBUILD_INTERVAL_HOURS ?? "24", 10);
  const ageMs = Date.now() - new Date(last).getTime();
  return ageMs > intervalHours * 3600 * 1000;
}

/**
 * The bridge writes to WooCommerce asynchronously, so a push that returned
 * `queued` leaves the row as `processing`. This settles those rows by polling
 * the plugin's queue — run from the cron so a product never gets stuck showing
 * `processing` forever when the in-request poll loop is disabled (the default).
 */
export async function reconcileWooQueue(env: Env, limit: number): Promise<number> {
  if ((env.WOO_PUSH_MODE ?? "plugin").toLowerCase() === "wcrest") return 0;
  const rows = await listWooQueuePending(env, limit);
  let settled = 0;
  for (const row of rows) {
    const st = await pollPluginQueue(env, row.woo_queue_id);
    if (!st) continue;
    if (st.status === "completed" || st.status === "passed" || st.status === "failed") {
      await updateWooQueueResult(env, row.sku, {
        status: pluginStatusToWoo(st.status),
        queueStatus: st.status,
        productId: st.productId,
        durationMs: st.durationMs,
        error: st.status === "failed" ? pluginQueueError(st.error) : null,
      });
      settled++;
    } else if (st.status === "processing") {
      await updateWooQueueResult(env, row.sku, { queueStatus: "processing" });
    }
  }
  return settled;
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
