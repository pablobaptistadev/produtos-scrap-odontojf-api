import type { Hono } from "hono";
import type { AppEnv } from "../env";

export function registerSystemRoutes(app: Hono<AppEnv>): void {
  app.get("/", (c) => {
    return c.json({
      service: "produtos-scrap-odontojf-api",
      status: "ok",
      docs: {
        health: "GET /health",
        rebuild: "POST /sync/rebuild",
        forceSku: "POST /sync/sku/:sku",
        listProducts: "GET /products",
        getProduct: "GET /products/:sku",
        queue: "GET /queue",
        monitor: "GET /monitor",
      },
    });
  });

  app.get("/health", async (c) => {
    let dbOk = false;
    try {
      const row = await c.env.DB.prepare("SELECT 1 AS ok").first<{ ok: number }>();
      dbOk = row?.ok === 1;
    } catch {
      dbOk = false;
    }
    return c.json({
      status: dbOk ? "ok" : "degraded",
      db: dbOk ? "ok" : "fail",
      time: new Date().toISOString(),
    });
  });

  app.get("/version", (c) => {
    return c.json({
      service: "produtos-scrap-odontojf-api",
      version: "1.0.0",
      env: {
        scrape_base: c.env.SCRAPE_BASE_URL,
        erp_base: c.env.ERP_BASE_URL,
        woo_base: c.env.WOO_BASE_URL,
        erp_token_set: Boolean(c.env.ERP_API_TOKEN) && c.env.ERP_API_TOKEN !== "PLACEHOLDER_NOT_SET",
        woo_creds_set:
          Boolean(c.env.WOO_CONSUMER_KEY) &&
          c.env.WOO_CONSUMER_KEY !== "PLACEHOLDER_NOT_SET" &&
          Boolean(c.env.WOO_CONSUMER_SECRET) &&
          c.env.WOO_CONSUMER_SECRET !== "PLACEHOLDER_NOT_SET",
      },
    });
  });
}
