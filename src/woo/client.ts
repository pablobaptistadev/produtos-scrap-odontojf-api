import type { Env } from "../env";
import { fetchWithTimeout, parseIntEnv } from "../core";

/**
 * WooCommerce REST API integration (legacy path — `WOO_PUSH_MODE=wcrest`).
 *
 * The default push path is the OdontoJF Woo Bridge plugin (`./plugin-client.ts`);
 * this module talks to core WooCommerce directly and is kept as a fallback.
 *
 * Endpoints used:
 *   - GET    /wp-json/wc/v3/products?sku={sku}                    (lookup by SKU)
 *   - POST   /wp-json/wc/v3/products                              (create)
 *   - PUT    /wp-json/wc/v3/products/{id}                         (update)
 *   - GET    /wp-json/wc/v3/products/{id}/variations              (list, paged)
 *   - POST   /wp-json/wc/v3/products/{id}/variations/batch        (bulk sync)
 *   - POST/PUT/DELETE .../variations[/{id}]                       (per-item fallback)
 *
 * Auth: HTTP Basic with consumer_key:consumer_secret over HTTPS (recommended
 * by WooCommerce when using SSL — query-string auth is the alternative for
 * non-HTTPS but we always assume HTTPS here).
 */

export interface WooVariationStats {
  created: number;
  updated: number;
  deleted: number;
  failed: number;
}

export type WooUpsertResult =
  | { status: "ok"; productId: number; created: boolean; response: unknown; variations?: WooVariationStats }
  | { status: "skipped"; reason: string }
  | { status: "failed"; reason: string; httpStatus?: number; response?: unknown };

function basicAuthHeader(env: Env): string {
  const token = btoa(`${env.WOO_CONSUMER_KEY}:${env.WOO_CONSUMER_SECRET}`);
  return `Basic ${token}`;
}

function authHeaders(env: Env): Record<string, string> {
  return {
    accept: "application/json",
    authorization: basicAuthHeader(env),
  };
}

async function findProductIdBySku(env: Env, sku: string, timeoutMs: number): Promise<number | null> {
  if (!sku) return null;
  const url = `${env.WOO_BASE_URL.replace(/\/$/, "")}/wp-json/wc/v3/products?sku=${encodeURIComponent(sku)}`;
  const res = await fetchWithTimeout(url, { timeoutMs, headers: authHeaders(env) });
  if (!res.ok) return null;
  const list = (await res.json()) as Array<{ id: number; sku: string }>;
  if (!Array.isArray(list) || list.length === 0) return null;
  const match = list.find((p) => p.sku === sku) ?? list[0];
  return match?.id ?? null;
}

/**
 * Strip everything WooCommerce would reject or that belongs elsewhere.
 * A variable parent must NOT carry its own sku/price/stock — those live on the
 * variations, and leaving them set makes Woo render the parent as purchasable.
 */
function buildParentBody(merged: Record<string, any>): Record<string, any> {
  const t = merged.type ?? "simple";
  const isVariable = t === "variable";
  const body: Record<string, any> = { ...merged };
  delete body.variations;
  delete body.extra;
  delete body.video_urls;
  delete body.pdf_urls;
  delete body.warnings;
  delete body.barcode;
  delete body.provider_code;
  delete body.brand;
  delete body.source_url;
  if (isVariable) {
    body.sku = "";
    body.manage_stock = false;
    delete body.stock_quantity;
    delete body.regular_price;
    delete body.sale_price;
  }
  if (body.regular_price != null) body.regular_price = String(body.regular_price);
  if (body.sale_price != null) body.sale_price = String(body.sale_price);
  return body;
}

function buildVariationBody(v: Record<string, any>): Record<string, any> {
  const body: Record<string, any> = {};
  if (v.sku != null && v.sku !== "") body.sku = String(v.sku);
  if (v.regular_price != null) body.regular_price = String(v.regular_price);
  if (v.sale_price != null) body.sale_price = String(v.sale_price);
  if (typeof v.manage_stock === "boolean") body.manage_stock = v.manage_stock;
  if (v.stock_quantity != null) body.stock_quantity = v.stock_quantity;
  if (v.stock_status) body.stock_status = v.stock_status;
  if (v.weight) body.weight = v.weight;
  if (v.dimensions) body.dimensions = v.dimensions;
  if (v.image && v.image.src) body.image = { src: v.image.src };
  if (Array.isArray(v.attributes) && v.attributes.length) body.attributes = v.attributes;
  if (Array.isArray(v.meta_data) && v.meta_data.length) body.meta_data = v.meta_data;
  return body;
}

/**
 * Reconcile the variation set: create what's missing, update what changed and
 * delete what the origin dropped. Matched by SKU. Tries the `/batch` endpoint
 * first (one round-trip) and degrades to per-item calls when the host rejects
 * it — some managed WP hosts cap batch size or time it out.
 */
async function syncWooVariations(
  env: Env,
  parentId: number,
  desired: Array<Record<string, any>>,
  timeoutMs: number,
): Promise<WooVariationStats> {
  const baseUrl = env.WOO_BASE_URL.replace(/\/$/, "");
  const stats: WooVariationStats = { created: 0, updated: 0, deleted: 0, failed: 0 };

  const existing: Array<{ id: number; sku?: string }> = [];
  for (let page = 1; page <= 10; page++) {
    const url = `${baseUrl}/wp-json/wc/v3/products/${parentId}/variations?per_page=100&page=${page}`;
    const res = await fetchWithTimeout(url, { timeoutMs, headers: authHeaders(env) });
    if (!res.ok) break;
    const arr = (await res.json()) as Array<{ id: number; sku?: string }>;
    if (!Array.isArray(arr) || arr.length === 0) break;
    existing.push(...arr);
    if (arr.length < 100) break;
  }

  const existingBySku = new Map<string, { id: number; sku?: string }>();
  for (const e of existing) {
    if (e.sku) existingBySku.set(e.sku, e);
  }

  const desiredBySku = new Set<string>();
  const create: Array<Record<string, any>> = [];
  const update: Array<Record<string, any>> = [];
  for (const v of desired) {
    const sku = v.sku != null ? String(v.sku) : "";
    if (!sku) {
      create.push(buildVariationBody(v));
      continue;
    }
    desiredBySku.add(sku);
    const match = existingBySku.get(sku);
    if (match) {
      update.push({ id: match.id, ...buildVariationBody(v) });
    } else {
      create.push(buildVariationBody(v));
    }
  }

  const deleteIds: number[] = [];
  for (const e of existing) {
    if (e.sku && !desiredBySku.has(e.sku)) deleteIds.push(e.id);
  }

  const batchUrl = `${baseUrl}/wp-json/wc/v3/products/${parentId}/variations/batch`;
  const batchBody = { create, update, delete: deleteIds };
  if (create.length === 0 && update.length === 0 && deleteIds.length === 0) {
    return stats;
  }

  try {
    const res = await fetchWithTimeout(batchUrl, {
      method: "POST",
      timeoutMs,
      headers: { ...authHeaders(env), "content-type": "application/json" },
      body: JSON.stringify(batchBody),
    });
    if (res.ok) {
      const parsed = (await res.json()) as { create?: unknown[]; update?: unknown[]; delete?: unknown[] };
      stats.created = (parsed.create ?? []).length;
      stats.updated = (parsed.update ?? []).length;
      stats.deleted = (parsed.delete ?? []).length;
      return stats;
    }
    const errText = await res.text();
    console.warn(
      `woo variations batch HTTP ${res.status} for parent ${parentId}: ${errText.slice(0, 200)} — falling back to per-item`,
    );
  } catch (err) {
    console.warn(
      `woo variations batch error for parent ${parentId}: ${err instanceof Error ? err.message : err} — falling back to per-item`,
    );
  }

  for (const c of create) {
    try {
      const res = await fetchWithTimeout(`${baseUrl}/wp-json/wc/v3/products/${parentId}/variations`, {
        method: "POST",
        timeoutMs,
        headers: { ...authHeaders(env), "content-type": "application/json" },
        body: JSON.stringify(c),
      });
      if (res.ok) stats.created++;
      else stats.failed++;
    } catch {
      stats.failed++;
    }
  }
  for (const u of update) {
    const id = u.id;
    if (!id) {
      stats.failed++;
      continue;
    }
    try {
      const res = await fetchWithTimeout(`${baseUrl}/wp-json/wc/v3/products/${parentId}/variations/${id}`, {
        method: "PUT",
        timeoutMs,
        headers: { ...authHeaders(env), "content-type": "application/json" },
        body: JSON.stringify(u),
      });
      if (res.ok) stats.updated++;
      else stats.failed++;
    } catch {
      stats.failed++;
    }
  }
  for (const id of deleteIds) {
    try {
      const res = await fetchWithTimeout(`${baseUrl}/wp-json/wc/v3/products/${parentId}/variations/${id}?force=true`, {
        method: "DELETE",
        timeoutMs,
        headers: authHeaders(env),
      });
      if (res.ok) stats.deleted++;
      else stats.failed++;
    } catch {
      stats.failed++;
    }
  }
  return stats;
}

export async function upsertWooProduct(
  env: Env,
  payload: { sku: string; merged: Record<string, unknown>; existingId?: number | null },
): Promise<WooUpsertResult> {
  const keyMissing = !env.WOO_CONSUMER_KEY || env.WOO_CONSUMER_KEY === "PLACEHOLDER_NOT_SET";
  const secretMissing = !env.WOO_CONSUMER_SECRET || env.WOO_CONSUMER_SECRET === "PLACEHOLDER_NOT_SET";
  if (keyMissing || secretMissing) {
    return { status: "skipped", reason: "WOO consumer credentials not configured" };
  }
  if (!env.WOO_BASE_URL) {
    return { status: "skipped", reason: "WOO_BASE_URL not configured" };
  }

  const timeoutMs = parseIntEnv(env.REQUEST_TIMEOUT_MS, 15000);
  const merged = payload.merged as Record<string, any>;
  const isVariable = merged.type === "variable";

  let productId = payload.existingId ?? null;
  if (!productId) {
    productId = await findProductIdBySku(env, payload.sku, timeoutMs);
  }

  const isUpdate = Boolean(productId);
  const baseUrl = env.WOO_BASE_URL.replace(/\/$/, "");
  const targetUrl = isUpdate
    ? `${baseUrl}/wp-json/wc/v3/products/${productId}`
    : `${baseUrl}/wp-json/wc/v3/products`;
  const parentBody = buildParentBody(merged);

  let parsed: any;
  try {
    const res = await fetchWithTimeout(targetUrl, {
      method: isUpdate ? "PUT" : "POST",
      timeoutMs,
      headers: { ...authHeaders(env), "content-type": "application/json" },
      body: JSON.stringify(parentBody),
    });
    const text = await res.text();
    try {
      parsed = JSON.parse(text);
    } catch {
      parsed = { raw: text };
    }
    if (!res.ok) {
      return { status: "failed", reason: `parent HTTP ${res.status}`, httpStatus: res.status, response: parsed };
    }
    productId = parsed?.id ?? productId ?? 0;
  } catch (err) {
    return { status: "failed", reason: err instanceof Error ? err.message : String(err) };
  }

  if (!productId) {
    return { status: "failed", reason: "woo did not return a product id", response: parsed };
  }

  let variationStats: WooVariationStats | undefined;
  if (isVariable) {
    const desired = merged.variations ?? [];
    if (Array.isArray(desired) && desired.length > 0) {
      try {
        variationStats = await syncWooVariations(env, productId, desired, timeoutMs);
      } catch {
        // The parent is already written — report it as ok and surface the
        // variation failure in the stats rather than losing the parent write.
        return {
          status: "ok",
          productId,
          created: !isUpdate,
          response: parsed,
          variations: { created: 0, updated: 0, deleted: 0, failed: desired.length },
        };
      }
    }
  }

  return {
    status: "ok",
    productId,
    created: !isUpdate,
    response: parsed,
    variations: variationStats,
  };
}
