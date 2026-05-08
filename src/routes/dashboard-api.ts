import type { Hono } from "hono";
import type { AppEnv } from "../env";

/**
 * JSON endpoints that the /dashboard SPA reads. Kept separate from the HTML
 * so the dashboard file stays static and the data shape can evolve in
 * isolation. All routes require the same api-key middleware as /products.
 */

const PERIOD_TO_HOURS: Record<string, number> = {
  today: 24,
  "7d": 24 * 7,
  "30d": 24 * 30,
  "90d": 24 * 90,
  "365d": 24 * 365,
};

export function registerDashboardApiRoutes(app: Hono<AppEnv>): void {
  app.get("/dashboard/api/stats", async (c) => {
    const period = c.req.query("period") ?? "today";
    const hours = PERIOD_TO_HOURS[period] ?? 24;
    const sinceIso = new Date(Date.now() - hours * 3600 * 1000).toISOString();

    // Count queue rows in the period.
    const totalRow = await c.env.DB.prepare(
      `SELECT COUNT(*) AS n FROM sync_queue WHERE created_at >= ?`,
    ).bind(sinceIso).first<{ n: number }>();
    const doneRow = await c.env.DB.prepare(
      `SELECT COUNT(*) AS n FROM sync_queue WHERE created_at >= ? AND status = 'done'`,
    ).bind(sinceIso).first<{ n: number }>();
    const failRow = await c.env.DB.prepare(
      `SELECT COUNT(*) AS n FROM sync_queue WHERE created_at >= ? AND status IN ('failed','dead')`,
    ).bind(sinceIso).first<{ n: number }>();
    const avgRow = await c.env.DB.prepare(
      `SELECT AVG((julianday(finished_at) - julianday(started_at)) * 24 * 3600 * 1000) AS ms
         FROM sync_queue
        WHERE created_at >= ? AND finished_at IS NOT NULL AND started_at IS NOT NULL`,
    ).bind(sinceIso).first<{ ms: number | null }>();

    // Current queue snapshot (not period-filtered).
    const queueRow = await c.env.DB.prepare(
      `SELECT
         SUM(CASE WHEN status='pending' THEN 1 ELSE 0 END) AS pending,
         SUM(CASE WHEN status='processing' THEN 1 ELSE 0 END) AS processing,
         SUM(CASE WHEN status='failed' THEN 1 ELSE 0 END) AS failed
       FROM sync_queue WHERE status IN ('pending','processing','failed')`,
    ).first<{ pending: number; processing: number; failed: number }>();

    const total = totalRow?.n ?? 0;
    const failed = failRow?.n ?? 0;
    return c.json({
      period,
      execucoes: total,
      concluidas: doneRow?.n ?? 0,
      falhas: failed,
      taxa_falha: total > 0 ? failed / total : 0,
      tempo_medio_ms: avgRow?.ms ?? null,
      pending: queueRow?.pending ?? 0,
      processing: queueRow?.processing ?? 0,
      fila_atual: (queueRow?.pending ?? 0) + (queueRow?.processing ?? 0),
    });
  });

  app.get("/dashboard/api/queue", async (c) => {
    const url = new URL(c.req.url);
    const status = url.searchParams.get("status") ?? "";
    const stage = url.searchParams.get("stage") ?? "";
    const sku = url.searchParams.get("sku") ?? "";
    const limit = clamp(Number.parseInt(url.searchParams.get("limit") ?? "80", 10), 1, 500);
    const offset = Math.max(0, Number.parseInt(url.searchParams.get("offset") ?? "0", 10));

    const where: string[] = [];
    const binds: unknown[] = [];
    if (status) { where.push("status = ?"); binds.push(status); }
    if (stage) { where.push("stage = ?"); binds.push(stage); }
    if (sku) { where.push("sku LIKE ?"); binds.push("%" + sku + "%"); }
    const whereSql = where.length ? `WHERE ${where.join(" AND ")}` : "";

    const totalRow = await c.env.DB.prepare(
      `SELECT COUNT(*) AS n FROM sync_queue ${whereSql}`,
    ).bind(...binds).first<{ n: number }>();
    const rowsRes = await c.env.DB.prepare(
      `SELECT id, sku, stage, status, attempts, last_error, created_at, started_at, finished_at, next_retry_at
         FROM sync_queue ${whereSql}
        ORDER BY id DESC LIMIT ? OFFSET ?`,
    ).bind(...binds, limit, offset).all<any>();

    const items = (rowsRes.results ?? []).map((r) => {
      const durationMs =
        r.started_at && r.finished_at
          ? Math.max(0, new Date(r.finished_at).getTime() - new Date(r.started_at).getTime())
          : null;
      return {
        _id: r.id,
        _detail: Object.entries({
          ID: r.id,
          SKU: r.sku ?? "—",
          Stage: r.stage,
          Status: r.status,
          Tentativas: r.attempts,
          "Criado em": r.created_at,
          "Iniciado em": r.started_at,
          "Finalizado em": r.finished_at,
          "Próximo retry": r.next_retry_at,
          "Duração (ms)": durationMs,
          Erro: r.last_error,
        }),
        ID: r.id,
        SKU: r.sku ?? "—",
        Stage: r.stage,
        Status: r.status,
        Tentativas: r.attempts,
        Tempo: durationMs,
        Criado: r.created_at,
      };
    });

    return c.json({
      total: totalRow?.n ?? 0,
      headers: [
        { field: "ID", label: "#", kind: "mono" },
        { field: "Criado", label: "Criado", kind: "date" },
        { field: "Stage", label: "Stage" },
        { field: "SKU", label: "SKU", kind: "mono" },
        { field: "Status", label: "Status", kind: "pill" },
        { field: "Tentativas", label: "Tent." },
        { field: "Tempo", label: "Tempo", kind: "time" },
      ],
      items,
    });
  });

  app.get("/dashboard/api/products", async (c) => {
    const url = new URL(c.req.url);
    const status = url.searchParams.get("status") ?? "";
    const stage = url.searchParams.get("stage") ?? ""; // unused but keeps the api consistent
    const sku = url.searchParams.get("sku") ?? "";
    const limit = clamp(Number.parseInt(url.searchParams.get("limit") ?? "80", 10), 1, 500);
    const offset = Math.max(0, Number.parseInt(url.searchParams.get("offset") ?? "0", 10));

    const where: string[] = [];
    const binds: unknown[] = [];
    // For products the "status" filter applies to scrape_status as a sensible default.
    if (status) { where.push("scrape_status = ?"); binds.push(status); }
    if (sku) { where.push("(sku LIKE ? OR external_sku LIKE ?)"); binds.push("%" + sku + "%", "%" + sku + "%"); }
    const whereSql = where.length ? `WHERE ${where.join(" AND ")}` : "";

    const totalRow = await c.env.DB.prepare(`SELECT COUNT(*) AS n FROM products ${whereSql}`).bind(...binds).first<{ n: number }>();
    const rowsRes = await c.env.DB.prepare(
      `SELECT sku, external_sku, slug, source_url, scrape_status, erp_status, woo_status, scrape_updated_at, woo_product_id, scrape_error
         FROM products ${whereSql}
        ORDER BY scrape_updated_at DESC NULLS LAST, sku ASC LIMIT ? OFFSET ?`,
    ).bind(...binds, limit, offset).all<any>();

    const items = (rowsRes.results ?? []).map((r) => ({
      _id: r.sku,
      _detail: Object.entries({
        SKU: r.sku,
        "SKU externo (loja API)": r.external_sku,
        Slug: r.slug,
        URL: r.source_url,
        "Scrape status": r.scrape_status,
        "Scrape atualizado": r.scrape_updated_at,
        "ERP status": r.erp_status,
        "Woo status": r.woo_status,
        "Woo product id": r.woo_product_id,
        "Último erro de scrape": r.scrape_error,
      }),
      SKU: r.sku,
      "Loja": r.external_sku ?? "—",
      Scrape: r.scrape_status,
      ERP: r.erp_status,
      Woo: r.woo_status,
      Atualizado: r.scrape_updated_at,
    }));

    return c.json({
      total: totalRow?.n ?? 0,
      headers: [
        { field: "SKU", label: "SKU", kind: "mono" },
        { field: "Loja", label: "ID Loja", kind: "mono" },
        { field: "Scrape", label: "Scrape", kind: "pill" },
        { field: "ERP", label: "ERP", kind: "pill" },
        { field: "Woo", label: "Woo", kind: "pill" },
        { field: "Atualizado", label: "Atualizado", kind: "date" },
      ],
      items,
    });
  });

  app.get("/dashboard/api/events", async (c) => {
    const url = new URL(c.req.url);
    const status = url.searchParams.get("status") ?? ""; // mapped to level
    const stage = url.searchParams.get("stage") ?? "";
    const sku = url.searchParams.get("sku") ?? "";
    const limit = clamp(Number.parseInt(url.searchParams.get("limit") ?? "80", 10), 1, 500);
    const offset = Math.max(0, Number.parseInt(url.searchParams.get("offset") ?? "0", 10));

    const where: string[] = [];
    const binds: unknown[] = [];
    if (status) { where.push("level = ?"); binds.push(status); }
    if (stage) { where.push("stage = ?"); binds.push(stage); }
    if (sku) { where.push("sku LIKE ?"); binds.push("%" + sku + "%"); }
    const whereSql = where.length ? `WHERE ${where.join(" AND ")}` : "";

    const totalRow = await c.env.DB.prepare(`SELECT COUNT(*) AS n FROM sync_events ${whereSql}`).bind(...binds).first<{ n: number }>();
    const rowsRes = await c.env.DB.prepare(
      `SELECT id, sku, stage, level, message, context_json, created_at
         FROM sync_events ${whereSql}
        ORDER BY id DESC LIMIT ? OFFSET ?`,
    ).bind(...binds, limit, offset).all<any>();

    const items = (rowsRes.results ?? []).map((r) => {
      let ctx: unknown = null;
      if (r.context_json) {
        try { ctx = JSON.parse(r.context_json); } catch { ctx = r.context_json; }
      }
      return {
        _id: r.id,
        _detail: Object.entries({
          ID: r.id,
          SKU: r.sku,
          Stage: r.stage,
          Level: r.level,
          Mensagem: r.message,
          Contexto: ctx,
          "Criado em": r.created_at,
        }),
        ID: r.id,
        Criado: r.created_at,
        Stage: r.stage,
        SKU: r.sku ?? "—",
        Level: r.level,
        Mensagem: r.message,
      };
    });

    return c.json({
      total: totalRow?.n ?? 0,
      headers: [
        { field: "ID", label: "#", kind: "mono" },
        { field: "Criado", label: "Criado", kind: "date" },
        { field: "Stage", label: "Stage" },
        { field: "SKU", label: "SKU", kind: "mono" },
        { field: "Level", label: "Level", kind: "pill" },
        { field: "Mensagem", label: "Mensagem" },
      ],
      items,
    });
  });
}

function clamp(n: number, min: number, max: number): number {
  return Math.max(min, Math.min(max, n));
}
