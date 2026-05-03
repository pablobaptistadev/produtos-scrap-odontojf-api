import type { Hono } from "hono";
import type { AppEnv } from "../env";
import { ApiError, safeJsonParse } from "../core";
import { listProducts, getProductBySku } from "../db/repo";

function expandProduct(row: any) {
  return {
    sku: row.sku,
    external_sku: row.external_sku ?? null,
    slug: row.slug,
    source_url: row.source_url,
    sku_provisional: Boolean(row.sku_provisional),
    scrape: {
      status: row.scrape_status,
      updated_at: row.scrape_updated_at,
      error: row.scrape_error,
      data: row.scrape_json ? safeJsonParse(row.scrape_json) : null,
    },
    erp: {
      status: row.erp_status,
      updated_at: row.erp_updated_at,
      error: row.erp_error,
      data: row.erp_json ? safeJsonParse(row.erp_json) : null,
    },
    merged: row.merged_json ? safeJsonParse(row.merged_json) : null,
    merged_updated_at: row.merged_updated_at,
    woo: {
      status: row.woo_status,
      product_id: row.woo_product_id,
      updated_at: row.woo_updated_at,
      error: row.woo_error,
      last_response: row.woo_last_response ? safeJsonParse(row.woo_last_response) : null,
    },
    created_at: row.created_at,
  };
}

export function registerProductRoutes(app: Hono<AppEnv>): void {
  app.get("/products", async (c) => {
    const url = new URL(c.req.url);
    const limit = Number.parseInt(url.searchParams.get("limit") ?? "50", 10);
    const offset = Number.parseInt(url.searchParams.get("offset") ?? "0", 10);
    const result = await listProducts(c.env, {
      limit,
      offset,
      scrapeStatus: url.searchParams.get("scrape_status") ?? undefined,
      erpStatus: url.searchParams.get("erp_status") ?? undefined,
      wooStatus: url.searchParams.get("woo_status") ?? undefined,
    });
    return c.json({
      total: result.total,
      limit,
      offset,
      items: result.items.map(expandProduct),
    });
  });

  app.get("/products/:sku", async (c) => {
    const sku = c.req.param("sku");
    if (!sku) throw new ApiError(400, "sku is required");
    const product = await getProductBySku(c.env, sku);
    if (!product) throw new ApiError(404, `product not found for sku=${sku}`);
    return c.json(expandProduct(product));
  });
}
