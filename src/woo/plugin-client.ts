import type { Env } from "../env";
import { fetchWithTimeout, parseIntEnv } from "../core";

/**
 * OdontoJF Woo Bridge integration (the WordPress plugin that owns product
 * creation on the storefront).
 *
 * Unlike `./client.ts` — which speaks core WooCommerce REST — this client posts
 * to the plugin's own namespace and gets back a QUEUE receipt, not a product:
 *
 *   POST {WOO_BASE_URL}/wp-json/{WOO_PLUGIN_NAMESPACE}/create-product
 *   -> { success, queued, queue_id, queue_position, product_id, ... }
 *
 * The plugin intercepts create/update/delete in `rest_pre_dispatch`, stores the
 * payload and answers in milliseconds; the real WooCommerce write happens later
 * in its worker. So the push stage records `queued` + `queue_id` and polls
 * `/queue-status` to learn the final outcome.
 *
 * Auth is a bearer token (the plugin's `ojf_api_secret` option), NOT the
 * WooCommerce consumer key/secret pair.
 *
 * SKU rule: variable parents are prefixed with `OD-` so the parent never
 * collides with the variation that carries the bare ERP code — WooCommerce
 * enforces SKU uniqueness across products AND variations.
 */

export type PluginPushResult =
  | { status: "queued"; endpoint: "create" | "delete"; queueId: number | null; wpProductId?: number | null; httpStatus?: number; response: unknown }
  | { status: "skipped"; reason: string }
  | { status: "failed"; reason: string; httpStatus?: number; response?: unknown };

export interface PluginQueueState {
  status: string;
  durationMs: number | null;
  productId: number | null;
  error: unknown;
}

function pluginCredsMissing(env: Env): string | null {
  if (!env.WOO_BASE_URL) return "WOO_BASE_URL not configured";
  if (!env.WOO_PLUGIN_API_KEY || env.WOO_PLUGIN_API_KEY === "PLACEHOLDER_NOT_SET") {
    return "WOO_PLUGIN_API_KEY not configured";
  }
  return null;
}

function pluginBase(env: Env): string {
  const ns = (env.WOO_PLUGIN_NAMESPACE ?? "odontojf/v1").replace(/^\/|\/$/g, "");
  return `${env.WOO_BASE_URL.replace(/\/$/, "")}/wp-json/${ns}`;
}

function authHeaders(env: Env): Record<string, string> {
  return {
    accept: "application/json",
    "content-type": "application/json",
    authorization: `Bearer ${env.WOO_PLUGIN_API_KEY}`,
  };
}

/** Stable key for a (sku, merged_updated_at) pair so a retry is not a new job. */
export async function computeIdemKey(sku: string, mergedUpdatedAt?: string | null): Promise<string> {
  const data = new TextEncoder().encode(`${sku}:${mergedUpdatedAt ?? ""}`);
  const digest = await crypto.subtle.digest("SHA-1", data);
  const bytes = new Uint8Array(digest);
  let hex = "";
  for (const b of bytes) hex += b.toString(16).padStart(2, "0");
  return hex;
}

export function buildPluginPayload(
  merged: Record<string, any>,
  wooSku: string,
  opts: { idemKey?: string; preferUpdate?: boolean } = {},
): Record<string, any> {
  const type = merged.type === "variable" ? "variable" : "simple";
  const isVariable = type === "variable";
  // Variable parents get `OD-` so they never collide with the variation that
  // carries the bare ERP code.
  const parentSku = isVariable && !wooSku.startsWith("OD-") ? `OD-${wooSku}` : wooSku;

  const body: Record<string, any> = {
    sku: parentSku,
    type,
    name: merged.name ?? wooSku,
  };
  if (merged.slug) body.slug = merged.slug;
  if (merged.status) body.status = merged.status;
  if (merged.description != null) body.description = merged.description;
  if (merged.short_description != null) body.short_description = merged.short_description;

  if (!isVariable) {
    if (merged.regular_price != null) body.regular_price = String(merged.regular_price);
    if (merged.sale_price != null) body.sale_price = String(merged.sale_price);
    if (merged.stock_quantity != null) {
      const q = Number(merged.stock_quantity) || 0;
      body.stock_quantity = q <= 0 ? 1 : q;
      body.stock_status = q <= 0 ? "instock" : merged.stock_status || "instock";
    } else if (merged.stock_status) {
      body.stock_status = merged.stock_status;
    }
    if (merged.weight != null && merged.weight !== "") body.weight = String(merged.weight);
  }
  if (merged.dimensions && typeof merged.dimensions === "object") body.dimensions = merged.dimensions;

  if (Array.isArray(merged.attributes)) body.attributes = merged.attributes;
  if (Array.isArray(merged.categories)) body.categories = merged.categories;
  if (Array.isArray(merged.images)) body.images = merged.images;
  if (Array.isArray(merged.meta_data)) body.meta_data = merged.meta_data;

  if (isVariable && Array.isArray(merged.variations)) {
    body.variations = merged.variations.map((v: Record<string, any>) => {
      const vb: Record<string, any> = {};
      if (v.sku != null && v.sku !== "") vb.sku = String(v.sku);
      if (v.regular_price != null) vb.regular_price = String(v.regular_price);
      if (v.sale_price != null) vb.sale_price = String(v.sale_price);
      if (v.stock_quantity != null) {
        const vq = Number(v.stock_quantity) || 0;
        vb.stock_quantity = vq <= 0 ? 1 : vq;
        vb.stock_status = vq <= 0 ? "instock" : v.stock_status || "instock";
      } else if (v.stock_status) {
        vb.stock_status = v.stock_status;
      }
      if (v.weight != null && v.weight !== "") vb.weight = String(v.weight);
      if (v.dimensions && typeof v.dimensions === "object") vb.dimensions = v.dimensions;
      if (v.image && typeof v.image === "object" && v.image.src) vb.image = v.image;
      if (Array.isArray(v.attributes)) vb.attributes = v.attributes;
      if (Array.isArray(v.meta_data)) vb.meta_data = v.meta_data;
      return vb;
    });
  }

  if (opts.idemKey) body.idem_key = opts.idemKey;
  if (opts.preferUpdate) body._odonto_prefer_update = true;
  return body;
}

export async function pushProductToPlugin(
  env: Env,
  args: { sku: string; merged: Record<string, unknown>; mergedUpdatedAt?: string | null; preferUpdate?: boolean },
): Promise<PluginPushResult> {
  const missing = pluginCredsMissing(env);
  if (missing) return { status: "skipped", reason: missing };

  const timeoutMs = parseIntEnv(env.REQUEST_TIMEOUT_MS, 15000);
  const idemKey = await computeIdemKey(args.sku, args.mergedUpdatedAt);
  const payload = buildPluginPayload(args.merged as Record<string, any>, args.sku, {
    idemKey,
    preferUpdate: args.preferUpdate,
  });
  const endpoint = args.preferUpdate ? "update-product" : "create-product";
  const url = `${pluginBase(env)}/${endpoint}`;

  try {
    const res = await fetchWithTimeout(url, {
      method: "POST",
      timeoutMs,
      headers: authHeaders(env),
      body: JSON.stringify(payload),
    });
    const text = await res.text();
    let parsed: unknown = text;
    try {
      parsed = JSON.parse(text);
    } catch {
      parsed = { raw: text };
    }
    if (!res.ok) {
      return { status: "failed", reason: `plugin HTTP ${res.status}`, httpStatus: res.status, response: parsed };
    }
    const obj = (parsed ?? {}) as Record<string, any>;
    const queueId = typeof obj.queue_id === "number" ? obj.queue_id : obj.queue_id ? Number(obj.queue_id) : null;
    return {
      status: "queued",
      endpoint: "create",
      queueId: Number.isFinite(queueId as number) ? (queueId as number) : null,
      wpProductId: typeof obj.product_id === "number" ? obj.product_id : null,
      httpStatus: res.status,
      response: parsed,
    };
  } catch (err) {
    return { status: "failed", reason: err instanceof Error ? err.message : String(err) };
  }
}

export async function pollPluginQueue(env: Env, queueId: number): Promise<PluginQueueState | null> {
  const missing = pluginCredsMissing(env);
  if (missing) return null;
  const timeoutMs = parseIntEnv(env.REQUEST_TIMEOUT_MS, 15000);
  const url = `${pluginBase(env)}/queue-status?queue_id=${encodeURIComponent(String(queueId))}`;
  try {
    const res = await fetchWithTimeout(url, { method: "GET", timeoutMs, headers: authHeaders(env) });
    if (!res.ok) return null;
    const obj = (await res.json()) as Record<string, any>;
    const dur = obj.duration_ms;
    const pid = obj.product_id;
    return {
      status: String(obj.status ?? "pending"),
      durationMs: typeof dur === "number" ? dur : dur != null && Number.isFinite(Number(dur)) ? Number(dur) : null,
      productId: typeof pid === "number" ? pid : pid != null && Number.isFinite(Number(pid)) ? Number(pid) : null,
      error: obj.error ?? null,
    };
  } catch {
    return null;
  }
}

export async function deleteProductOnPlugin(env: Env, sku: string): Promise<PluginPushResult> {
  const missing = pluginCredsMissing(env);
  if (missing) return { status: "skipped", reason: missing };
  const timeoutMs = parseIntEnv(env.REQUEST_TIMEOUT_MS, 15000);
  const url = `${pluginBase(env)}/delete-product`;
  try {
    const res = await fetchWithTimeout(url, {
      method: "POST",
      timeoutMs,
      headers: authHeaders(env),
      body: JSON.stringify({ sku }),
    });
    const text = await res.text();
    let parsed: unknown = text;
    try {
      parsed = JSON.parse(text);
    } catch {
      parsed = { raw: text };
    }
    if (!res.ok) return { status: "failed", reason: `plugin HTTP ${res.status}`, httpStatus: res.status, response: parsed };
    const obj = (parsed ?? {}) as Record<string, any>;
    return { status: "queued", endpoint: "delete", queueId: obj.queue_id ?? null, response: parsed };
  } catch (err) {
    return { status: "failed", reason: err instanceof Error ? err.message : String(err) };
  }
}

export async function pushCategoriesToPlugin(
  env: Env,
  paths: unknown,
  wipe = false,
): Promise<PluginPushResult> {
  const missing = pluginCredsMissing(env);
  if (missing) return { status: "skipped", reason: missing };
  // Category sync walks the whole tree server-side — needs a much longer budget.
  const timeoutMs = Math.max(180000, parseIntEnv(env.REQUEST_TIMEOUT_MS, 15000));
  const url = `${pluginBase(env)}/sync-categories`;
  try {
    const res = await fetchWithTimeout(url, {
      method: "POST",
      timeoutMs,
      headers: authHeaders(env),
      body: JSON.stringify({ paths, wipe }),
    });
    const text = await res.text();
    let parsed: unknown = text;
    try {
      parsed = JSON.parse(text);
    } catch {
      parsed = { raw: text };
    }
    if (!res.ok) return { status: "failed", reason: `plugin HTTP ${res.status}`, httpStatus: res.status, response: parsed };
    // NOTE: `endpoint: "delete"` here mirrors production verbatim. It is not
    // meaningful for categories (nothing reads it) — kept so the restored
    // source builds byte-identically to the deployed bundle.
    return { status: "queued", endpoint: "delete", queueId: null, response: parsed };
  } catch (err) {
    return { status: "failed", reason: err instanceof Error ? err.message : String(err) };
  }
}

export async function setRootParentOnPlugin(env: Env, parentId: unknown): Promise<PluginPushResult> {
  const missing = pluginCredsMissing(env);
  if (missing) return { status: "skipped", reason: missing };
  const url = `${pluginBase(env)}/sync-categories`;
  try {
    const res = await fetchWithTimeout(url, {
      method: "POST",
      timeoutMs: 120000,
      headers: authHeaders(env),
      body: JSON.stringify({ root_parent: parentId }),
    });
    const text = await res.text();
    let parsed: unknown = text;
    try {
      parsed = JSON.parse(text);
    } catch {
      parsed = { raw: text };
    }
    if (!res.ok) return { status: "failed", reason: `plugin HTTP ${res.status}`, httpStatus: res.status, response: parsed };
    // Same verbatim quirk as pushCategoriesToPlugin — see note above.
    return { status: "queued", endpoint: "delete", queueId: null, response: parsed };
  } catch (err) {
    return { status: "failed", reason: err instanceof Error ? err.message : String(err) };
  }
}

/** Map the plugin's queue status onto the `products.woo_status` vocabulary. */
export function pluginStatusToWoo(status: string): "ok" | "failed" | "processing" {
  if (status === "completed" || status === "passed") return "ok";
  if (status === "failed") return "failed";
  return "processing";
}
