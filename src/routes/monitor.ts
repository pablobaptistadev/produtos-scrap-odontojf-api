import type { Hono } from "hono";
import type { AppEnv } from "../env";
import { htmlResponse } from "../core";

export function registerMonitorRoutes(app: Hono<AppEnv>): void {
  app.get("/monitor", async (c) => {
    const productCounts = await c.env.DB.prepare(
      `SELECT
         COUNT(*) AS total,
         SUM(CASE WHEN scrape_status = 'ok' THEN 1 ELSE 0 END) AS scraped,
         SUM(CASE WHEN erp_status = 'ok' THEN 1 ELSE 0 END) AS erp_ok,
         SUM(CASE WHEN woo_status = 'ok' THEN 1 ELSE 0 END) AS woo_ok,
         SUM(CASE WHEN woo_status = 'failed' THEN 1 ELSE 0 END) AS woo_failed
       FROM products`,
    ).first<{ total: number; scraped: number; erp_ok: number; woo_ok: number; woo_failed: number }>();

    const queueCounts = await c.env.DB.prepare(
      `SELECT status, COUNT(*) AS cnt FROM sync_queue GROUP BY status`,
    ).all<{ status: string; cnt: number }>();

    const recentEvents = await c.env.DB.prepare(
      `SELECT id, sku, stage, level, message, created_at FROM sync_events ORDER BY id DESC LIMIT 25`,
    ).all<{ id: number; sku: string | null; stage: string; level: string; message: string; created_at: string }>();

    const lastRebuild = await c.env.DB.prepare(
      `SELECT value FROM app_state WHERE key = 'last_rebuild_at'`,
    ).first<{ value: string }>();

    const rows = (queueCounts.results ?? [])
      .map((r) => `<tr><td>${escape(r.status)}</td><td>${r.cnt}</td></tr>`)
      .join("");

    const events = (recentEvents.results ?? [])
      .map(
        (e) => `
      <tr>
        <td>${escape(e.created_at)}</td>
        <td>${escape(e.stage)}</td>
        <td><span class="lvl lvl-${escape(e.level)}">${escape(e.level)}</span></td>
        <td>${escape(e.sku ?? "-")}</td>
        <td>${escape(e.message)}</td>
      </tr>`,
      )
      .join("");

    const html = `<!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8" />
<title>OdontoJF Sync Monitor</title>
<style>
  body { font-family: -apple-system, system-ui, sans-serif; margin: 24px; color: #222; max-width: 1100px; }
  h1 { margin: 0 0 8px; }
  .grid { display: grid; grid-template-columns: repeat(5, 1fr); gap: 12px; margin: 16px 0 24px; }
  .card { padding: 14px; border: 1px solid #ddd; border-radius: 8px; background: #fafafa; }
  .card .num { font-size: 26px; font-weight: 700; }
  .card .lbl { font-size: 12px; color: #666; text-transform: uppercase; }
  table { border-collapse: collapse; width: 100%; margin-top: 8px; }
  th, td { border: 1px solid #eee; padding: 6px 10px; text-align: left; font-size: 13px; vertical-align: top; }
  th { background: #f0f0f0; }
  .lvl { padding: 2px 6px; border-radius: 4px; font-size: 11px; text-transform: uppercase; }
  .lvl-info { background: #def; color: #036; }
  .lvl-warn { background: #fec; color: #850; }
  .lvl-error { background: #fcc; color: #900; }
  .meta { color: #666; font-size: 12px; }
</style>
</head>
<body>
  <h1>OdontoJF Sync Monitor</h1>
  <div class="meta">Last rebuild: ${escape(lastRebuild?.value ?? "never")}</div>

  <div class="grid">
    <div class="card"><div class="lbl">Products</div><div class="num">${productCounts?.total ?? 0}</div></div>
    <div class="card"><div class="lbl">Scraped</div><div class="num">${productCounts?.scraped ?? 0}</div></div>
    <div class="card"><div class="lbl">ERP ok</div><div class="num">${productCounts?.erp_ok ?? 0}</div></div>
    <div class="card"><div class="lbl">Woo ok</div><div class="num">${productCounts?.woo_ok ?? 0}</div></div>
    <div class="card"><div class="lbl">Woo failed</div><div class="num">${productCounts?.woo_failed ?? 0}</div></div>
  </div>

  <h2>Queue status</h2>
  <table><thead><tr><th>Status</th><th>Count</th></tr></thead><tbody>${rows || "<tr><td colspan=2>(empty)</td></tr>"}</tbody></table>

  <h2>Recent events</h2>
  <table>
    <thead><tr><th>When</th><th>Stage</th><th>Level</th><th>SKU</th><th>Message</th></tr></thead>
    <tbody>${events || "<tr><td colspan=5>(no events yet)</td></tr>"}</tbody>
  </table>
</body>
</html>`;
    return htmlResponse(html);
  });
}

function escape(value: string | null | undefined): string {
  if (value == null) return "";
  return String(value)
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;");
}
