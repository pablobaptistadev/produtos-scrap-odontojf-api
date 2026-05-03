import type { Env } from "../env";
import { fetchWithTimeout, parseIntEnv } from "../core";

/**
 * WooCommerce REST API integration.
 *
 * Endpoints used:
 *   - GET    /wp-json/wc/v3/products?sku={sku}   (lookup by SKU)
 *   - POST   /wp-json/wc/v3/products             (create)
 *   - PUT    /wp-json/wc/v3/products/{id}        (update)
 *
 * Auth: HTTP Basic with consumer_key:consumer_secret over HTTPS (recommended
 * by WooCommerce when using SSL — query-string auth is the alternative for
 * non-HTTPS but we always assume HTTPS here).
 */

export type WooUpsertResult =
  | { status: "ok"; productId: number; created: boolean; response: unknown }
  | { status: "skipped"; reason: string }
  | { status: "failed"; reason: string; httpStatus?: number; response?: unknown };

function basicAuthHeader(env: Env): string {
  const token = btoa(`${env.WOO_CONSUMER_KEY}:${env.WOO_CONSUMER_SECRET}`);
  return `Basic ${token}`;
}

async function findProductIdBySku(env: Env, sku: string, timeoutMs: number): Promise<number | null> {
  const url = `${env.WOO_BASE_URL.replace(/\/$/, "")}/wp-json/wc/v3/products?sku=${encodeURIComponent(sku)}`;
  const res = await fetchWithTimeout(url, {
    timeoutMs,
    headers: {
      accept: "application/json",
      authorization: basicAuthHeader(env),
    },
  });
  if (!res.ok) return null;
  const list = (await res.json()) as Array<{ id: number; sku: string }>;
  if (!Array.isArray(list) || list.length === 0) return null;
  const match = list.find((p) => p.sku === sku) ?? list[0];
  return match?.id ?? null;
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
  let productId = payload.existingId ?? null;
  if (!productId) {
    productId = await findProductIdBySku(env, payload.sku, timeoutMs);
  }

  const isUpdate = Boolean(productId);
  const baseUrl = env.WOO_BASE_URL.replace(/\/$/, "");
  const targetUrl = isUpdate
    ? `${baseUrl}/wp-json/wc/v3/products/${productId}`
    : `${baseUrl}/wp-json/wc/v3/products`;

  try {
    const res = await fetchWithTimeout(targetUrl, {
      method: isUpdate ? "PUT" : "POST",
      timeoutMs,
      headers: {
        "content-type": "application/json",
        accept: "application/json",
        authorization: basicAuthHeader(env),
      },
      body: JSON.stringify(payload.merged),
    });
    const text = await res.text();
    let parsed: unknown = text;
    try {
      parsed = JSON.parse(text);
    } catch {
      parsed = { raw: text };
    }
    if (!res.ok) {
      return { status: "failed", reason: `HTTP ${res.status}`, httpStatus: res.status, response: parsed };
    }
    const id = (parsed as { id?: number })?.id ?? productId ?? 0;
    return { status: "ok", productId: id, created: !isUpdate, response: parsed };
  } catch (err) {
    return { status: "failed", reason: err instanceof Error ? err.message : String(err) };
  }
}
