import type { Hono } from "hono";
import type { AppEnv } from "../env";
import { ApiError, safeJsonParse } from "../core";
import {
  listSyncQueue,
  resetSyncRowToPending,
  deleteSyncRow,
  getSyncQueueRow,
  listRecentEvents,
} from "../db/repo";

export function registerQueueRoutes(app: Hono<AppEnv>): void {
  app.get("/queue", async (c) => {
    const url = new URL(c.req.url);
    const limit = Number.parseInt(url.searchParams.get("limit") ?? "50", 10);
    const offset = Number.parseInt(url.searchParams.get("offset") ?? "0", 10);
    const result = await listSyncQueue(c.env, {
      limit,
      offset,
      status: url.searchParams.get("status") ?? undefined,
      stage: url.searchParams.get("stage") ?? undefined,
    });

    // also include aggregate counts
    const counts = await c.env.DB.prepare(
      `SELECT status, COUNT(*) AS cnt FROM sync_queue GROUP BY status`,
    ).all<{ status: string; cnt: number }>();

    return c.json({
      total: result.total,
      counts: Object.fromEntries(counts.results.map((r) => [r.status, r.cnt])),
      limit,
      offset,
      items: result.items.map((r) => ({
        ...r,
        payload: r.payload_json ? safeJsonParse(r.payload_json) : null,
      })),
    });
  });

  app.get("/queue/:id", async (c) => {
    const id = Number.parseInt(c.req.param("id"), 10);
    if (!Number.isFinite(id)) throw new ApiError(400, "invalid id");
    const row = await getSyncQueueRow(c.env, id);
    if (!row) throw new ApiError(404, "queue row not found");
    return c.json({ ...row, payload: row.payload_json ? safeJsonParse(row.payload_json) : null });
  });

  app.post("/queue/:id/retry", async (c) => {
    const id = Number.parseInt(c.req.param("id"), 10);
    if (!Number.isFinite(id)) throw new ApiError(400, "invalid id");
    const row = await getSyncQueueRow(c.env, id);
    if (!row) throw new ApiError(404, "queue row not found");
    await resetSyncRowToPending(c.env, id);
    await c.env.SYNC_QUEUE.send({
      stage: row.stage as any,
      sku: row.sku,
      slug: row.slug,
      url: row.url,
      queue_row_id: row.id,
    });
    return c.json({ ok: true, retried: id });
  });

  app.delete("/queue/:id", async (c) => {
    const id = Number.parseInt(c.req.param("id"), 10);
    if (!Number.isFinite(id)) throw new ApiError(400, "invalid id");
    await deleteSyncRow(c.env, id);
    return c.json({ ok: true, deleted: id });
  });

  app.post("/queue/cleanup", async (c) => {
    // Remove duplicates: for each (stage, sku) keep only the most recent row;
    // also drop done rows older than 7 days to keep the table small.
    const url = new URL(c.req.url);
    const dropDoneOlderThanDays = Number.parseInt(url.searchParams.get("done_days") ?? "7", 10);
    const dedup = await c.env.DB.prepare(
      `DELETE FROM sync_queue
        WHERE id NOT IN (
          SELECT MAX(id) FROM sync_queue WHERE status IN ('pending','failed') GROUP BY stage, COALESCE(sku, slug, '')
        )
        AND status IN ('pending','failed')`,
    ).run();
    const purged = await c.env.DB.prepare(
      `DELETE FROM sync_queue WHERE status = 'done' AND finished_at < datetime('now', ?)`,
    )
      .bind(`-${dropDoneOlderThanDays} days`)
      .run();
    return c.json({
      deduped: dedup.meta?.changes ?? 0,
      purged_done: purged.meta?.changes ?? 0,
    });
  });

  app.get("/events", async (c) => {
    const url = new URL(c.req.url);
    const limit = Number.parseInt(url.searchParams.get("limit") ?? "50", 10);
    const sku = url.searchParams.get("sku") ?? undefined;
    const events = await listRecentEvents(c.env, { limit, sku });
    return c.json({ items: events });
  });
}
