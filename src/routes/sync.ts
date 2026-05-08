import type { Hono } from "hono";
import type { AppEnv } from "../env";
import { ApiError } from "../core";
import { enqueueRebuild, enqueueStage } from "../sync/orchestrator";
import { getProductBySku } from "../db/repo";

export function registerSyncRoutes(app: Hono<AppEnv>): void {
  app.post("/sync/rebuild", async (c) => {
    const id = await enqueueRebuild(c.env, { reason: "manual trigger" });
    return c.json({ enqueued: true, queue_row_id: id });
  });

  app.post("/sync/sku/:sku", async (c) => {
    const sku = c.req.param("sku");
    if (!sku) throw new ApiError(400, "sku is required");
    const product = await getProductBySku(c.env, sku);
    if (!product) {
      throw new ApiError(404, `product not found for sku=${sku}`);
    }
    const stageParam = (c.req.query("stage") as "scrape" | "erp" | "merge" | "media" | "push" | undefined) ?? "scrape";
    const id = await enqueueStage(c.env, {
      stage: stageParam,
      sku,
      slug: product.slug,
      url: product.source_url,
    });
    return c.json({ enqueued: true, queue_row_id: id, stage: stageParam });
  });
}
