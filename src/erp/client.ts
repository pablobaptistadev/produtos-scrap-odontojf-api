import type { Env } from "../env";
import { fetchWithTimeout, parseIntEnv } from "../core";

/**
 * ERP integration — http://45.227.82.180:8082/ecommerceapi/v1/
 *
 * NOTE: the exact request shape is documented at
 *   http://45.227.82.180:8082/ecommerceapi/v1/documentacao/
 * and may use Bearer token, X-API-Key, or basic auth. We default to Bearer +
 * `GET /produto/{sku}`, which is the most common pattern for the ecommerce
 * variant of similar Linx/SAP/Vetorh integrations.
 *
 * If the real endpoint differs, only this file needs to be edited — the rest
 * of the pipeline treats the response as opaque JSON.
 */

export type ErpFetchResult =
  | { status: "ok"; data: unknown; httpStatus: number }
  | { status: "skipped"; reason: string }
  | { status: "failed"; reason: string; httpStatus?: number };

export async function fetchProductFromErp(env: Env, sku: string): Promise<ErpFetchResult> {
  if (!env.ERP_API_TOKEN || env.ERP_API_TOKEN === "PLACEHOLDER_NOT_SET") {
    return { status: "skipped", reason: "ERP_API_TOKEN not configured" };
  }
  const base = (env.ERP_BASE_URL ?? "").replace(/\/$/, "");
  if (!base) {
    return { status: "skipped", reason: "ERP_BASE_URL not configured" };
  }
  const timeoutMs = parseIntEnv(env.REQUEST_TIMEOUT_MS, 15000);
  const url = `${base}/produto/${encodeURIComponent(sku)}`;
  try {
    const res = await fetchWithTimeout(url, {
      timeoutMs,
      headers: {
        accept: "application/json",
        authorization: `Bearer ${env.ERP_API_TOKEN}`,
      },
    });
    const text = await res.text();
    if (!res.ok) {
      return { status: "failed", reason: `HTTP ${res.status}: ${text.slice(0, 200)}`, httpStatus: res.status };
    }
    let data: unknown = text;
    try {
      data = JSON.parse(text);
    } catch {
      // ERP returned non-JSON; keep raw text under data
      data = { raw: text };
    }
    return { status: "ok", data, httpStatus: res.status };
  } catch (err) {
    return { status: "failed", reason: err instanceof Error ? err.message : String(err) };
  }
}
