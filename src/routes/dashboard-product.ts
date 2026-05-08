import type { Hono } from "hono";
import type { AppEnv } from "../env";

/**
 * /dashboard/product/<sku> — full preview of a single product:
 * gallery + price + stock + dimensions + categories + attributes +
 * variations (with per-row image, sku, price, stock, dimensions, barcode,
 * provider_code) + videos (YouTube embed) + PDFs (download) + rich
 * description + raw merged_json + pipeline status.
 *
 * Reads /dashboard/api/product/<sku> (defined in dashboard-api.ts).
 */
export function registerDashboardProductRoutes(app: Hono<AppEnv>): void {
  app.get("/dashboard/product/:sku", (c) => {
    return new Response(PRODUCT_HTML, {
      headers: {
        "content-type": "text/html; charset=utf-8",
        // Dashboard markup changes often during this build phase — never cache.
        "cache-control": "no-store, no-cache, must-revalidate",
      },
    });
  });
}

const PRODUCT_HTML = /* html */ `<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <!-- Don't leak the referrer when loading mirrored images. The R2 custom
       domain (media.odontoapi.wpatomic.com.br) sits behind the same CF
       zone as wpatomic.com.br, which has Hotlink Protection on. With a
       cross-origin Referer the CF edge returns Error 1011 / 403; with
       no-referrer the request passes. -->
  <meta name="referrer" content="no-referrer" />
  <title>OdontoJF — Produto</title>
  <style>
    :root {
      --bg-0: #060812;
      --bg-1: #0a0e1f;
      --bg-2: #0f1530;
      --bg-3: #131c40;
      --border: rgba(120, 140, 220, 0.15);
      --border-strong: rgba(120, 140, 220, 0.32);
      --text-0: #f4f7ff;
      --text-1: #b9c2e0;
      --text-2: #6f7aa3;
      --accent: #38bdf8;
      --accent-2: #818cf8;
      --green: #4ade80;
      --orange: #fb923c;
      --red: #f87171;
      --yellow: #fbbf24;
    }
    * { box-sizing: border-box; }
    html, body { margin: 0; padding: 0; min-height: 100vh; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Inter, sans-serif; color: var(--text-0); background: radial-gradient(1200px 800px at -10% -10%, #1b2152 0%, transparent 60%), radial-gradient(900px 700px at 110% 0%, #2c1c5b 0%, transparent 50%), var(--bg-0); }
    a { color: var(--accent); text-decoration: none; }
    a:hover { text-decoration: underline; }
    button { font: inherit; color: inherit; cursor: pointer; }
    .app { max-width: 1480px; margin: 0 auto; padding: 28px 32px; }
    /* topbar */
    .topbar { display: flex; align-items: center; justify-content: space-between; margin-bottom: 22px; }
    .back { padding: 9px 16px; background: var(--bg-2); border: 1px solid var(--border); border-radius: 10px; font-size: 13px; }
    .back:hover { border-color: var(--border-strong); }
    /* header card */
    .header { background: linear-gradient(180deg, rgba(20, 26, 60, 0.7), rgba(11, 15, 38, 0.7)); border: 1px solid var(--border); border-radius: 18px; padding: 24px 26px; margin-bottom: 22px; backdrop-filter: blur(8px); }
    .header .breadcrumb { font-size: 12px; color: var(--text-2); margin-bottom: 10px; letter-spacing: 0.5px; }
    .header h1 { margin: 0 0 8px; font-size: 28px; line-height: 1.15; letter-spacing: -0.5px; font-weight: 700; }
    .header .meta { display: flex; align-items: center; gap: 14px; flex-wrap: wrap; }
    .id-row { display: flex; gap: 18px; flex-wrap: wrap; margin: 14px 0 0; padding: 12px 14px; background: rgba(56, 189, 248, 0.06); border: 1px solid rgba(56, 189, 248, 0.18); border-radius: 12px; }
    .id-cell { display: flex; flex-direction: column; gap: 2px; min-width: 110px; }
    .id-cell .id-label { font-size: 10px; letter-spacing: 1px; text-transform: uppercase; color: var(--text-2); font-weight: 600; }
    .id-cell .id-value { font-family: ui-monospace, SFMono-Regular, "Menlo", monospace; font-size: 15px; color: var(--text-0); font-weight: 600; }
    .id-cell .id-value.empty { color: var(--text-2); font-weight: 400; }
    .id-sep { width: 1px; align-self: stretch; background: rgba(120, 140, 220, 0.18); }
    .badges { display: inline-flex; gap: 8px; flex-wrap: wrap; align-items: center; }
    .badge { display: inline-block; padding: 4px 12px; border-radius: 999px; font-size: 11px; font-weight: 600; letter-spacing: 0.5px; text-transform: uppercase; }
    .badge.simple { background: rgba(56, 189, 248, 0.15); color: var(--accent); }
    .badge.variable { background: rgba(129, 140, 248, 0.18); color: var(--accent-2); }
    .badge.instock, .badge.ok, .badge.done { background: rgba(74, 222, 128, 0.15); color: var(--green); }
    .badge.outofstock, .badge.failed, .badge.error { background: rgba(248, 113, 113, 0.18); color: var(--red); }
    .badge.skipped, .badge.pending, .badge.processing { background: rgba(251, 191, 36, 0.15); color: var(--yellow); }
    .badge.publish { background: rgba(74, 222, 128, 0.15); color: var(--green); }
    .badge.draft { background: rgba(148, 163, 184, 0.18); color: var(--text-1); }
    .meta .sub { color: var(--text-1); font-size: 13px; }
    .meta .sub strong { color: var(--text-0); }
    /* split layout */
    .split { display: grid; grid-template-columns: 480px 1fr; gap: 22px; align-items: start; }
    @media (max-width: 1100px) { .split { grid-template-columns: 1fr; } }
    .col { display: flex; flex-direction: column; gap: 18px; }
    /* gallery */
    .gallery { background: var(--bg-1); border: 1px solid var(--border); border-radius: 18px; padding: 16px; }
    .gallery .main-img { width: 100%; aspect-ratio: 1; background: var(--bg-2); border-radius: 12px; display: flex; align-items: center; justify-content: center; overflow: hidden; cursor: zoom-in; }
    .gallery .main-img img { max-width: 100%; max-height: 100%; object-fit: contain; }
    .gallery .thumbs { display: grid; grid-template-columns: repeat(auto-fill, minmax(64px, 1fr)); gap: 8px; margin-top: 12px; }
    .gallery .thumb { aspect-ratio: 1; background: var(--bg-2); border: 2px solid transparent; border-radius: 8px; overflow: hidden; cursor: pointer; transition: border 100ms; }
    .gallery .thumb img { width: 100%; height: 100%; object-fit: cover; }
    .gallery .thumb.active { border-color: var(--accent); }
    .gallery .empty { padding: 80px 0; text-align: center; color: var(--text-2); font-size: 13px; }
    /* card */
    .card { background: linear-gradient(180deg, rgba(15, 21, 48, 0.6), rgba(11, 15, 38, 0.6)); border: 1px solid var(--border); border-radius: 18px; backdrop-filter: blur(8px); }
    .card-h { padding: 14px 18px; border-bottom: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between; font-size: 12px; color: var(--text-2); text-transform: uppercase; letter-spacing: 0.7px; }
    .card-b { padding: 18px 22px; }
    /* spec grid */
    .specs { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
    .spec { display: flex; flex-direction: column; gap: 4px; }
    .spec .label { font-size: 11px; letter-spacing: 0.6px; text-transform: uppercase; color: var(--text-2); font-weight: 600; }
    .spec .value { font-size: 16px; color: var(--text-0); font-weight: 500; word-break: break-word; }
    .spec .value.big { font-size: 24px; font-weight: 700; }
    .spec .value.green { color: var(--green); }
    .spec .value.red { color: var(--red); }
    .spec .value.yellow { color: var(--yellow); }
    .spec .value.mono { font-family: ui-monospace, SFMono-Regular, "Menlo", monospace; font-size: 13px; }
    /* table */
    .table { width: 100%; border-collapse: collapse; font-size: 13px; }
    .table th { text-align: left; padding: 10px 14px; font-size: 11px; letter-spacing: 0.7px; text-transform: uppercase; color: var(--text-2); border-bottom: 1px solid var(--border); font-weight: 600; }
    .table td { padding: 12px 14px; border-bottom: 1px solid var(--border); color: var(--text-1); vertical-align: middle; }
    .table tr:last-child td { border-bottom: none; }
    .table img { width: 36px; height: 36px; object-fit: cover; border-radius: 6px; background: var(--bg-2); display: block; }
    .pill { display: inline-block; padding: 3px 10px; border-radius: 999px; font-size: 11px; font-weight: 600; letter-spacing: 0.3px; }
    .pill.instock, .pill.ok, .pill.done { background: rgba(74, 222, 128, 0.15); color: var(--green); }
    .pill.outofstock, .pill.failed { background: rgba(248, 113, 113, 0.18); color: var(--red); }
    .pill.skipped, .pill.pending { background: rgba(251, 191, 36, 0.15); color: var(--yellow); }
    /* attribute pills */
    .attrs { display: flex; flex-wrap: wrap; gap: 8px; }
    .attr { padding: 8px 14px; background: var(--bg-2); border: 1px solid var(--border); border-radius: 10px; font-size: 13px; }
    .attr .a-name { color: var(--text-2); margin-right: 6px; font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; }
    .attr .a-value { color: var(--text-0); font-weight: 600; }
    /* category breadcrumb */
    .cat-crumb { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
    .cat-crumb .crumb { padding: 6px 12px; background: var(--bg-2); border: 1px solid var(--border); border-radius: 8px; font-size: 13px; color: var(--text-1); }
    .cat-crumb .sep { color: var(--text-2); }
    /* video / pdf cards */
    .media-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
    @media (max-width: 800px) { .media-grid { grid-template-columns: 1fr; } }
    .video iframe { width: 100%; aspect-ratio: 16/9; border: 0; border-radius: 10px; background: black; }
    .pdf-link { display: flex; align-items: center; justify-content: space-between; padding: 12px 14px; background: var(--bg-2); border: 1px solid var(--border); border-radius: 10px; font-size: 13px; color: var(--text-0); }
    .pdf-link:hover { border-color: var(--border-strong); text-decoration: none; }
    .pdf-link .pdf-name { color: var(--text-0); font-weight: 600; }
    .pdf-link .pdf-go { color: var(--accent); font-size: 12px; }
    /* description html */
    .desc-html { color: var(--text-1); font-size: 14px; line-height: 1.6; }
    .desc-html p { margin: 0 0 12px; }
    .desc-html ul, .desc-html ol { padding-left: 22px; margin: 0 0 12px; }
    .desc-html li { margin-bottom: 4px; }
    .desc-html strong { color: var(--text-0); }
    .desc-html img { max-width: 100%; height: auto; border-radius: 8px; margin: 10px 0; display: block; }
    .desc-html iframe { max-width: 100%; border-radius: 10px; margin: 10px 0; }
    .desc-html a { color: var(--accent); }
    /* timeline */
    .timeline { display: grid; grid-template-columns: repeat(5, 1fr); gap: 10px; }
    @media (max-width: 800px) { .timeline { grid-template-columns: repeat(2, 1fr); } }
    .step { padding: 12px 14px; background: var(--bg-2); border: 1px solid var(--border); border-radius: 10px; }
    .step .step-name { font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; color: var(--text-2); margin-bottom: 4px; font-weight: 600; }
    .step .step-status { font-size: 14px; font-weight: 600; }
    .step .step-when { font-size: 11px; color: var(--text-2); margin-top: 4px; font-family: ui-monospace, monospace; }
    .step.ok .step-status { color: var(--green); }
    .step.failed .step-status, .step.error .step-status { color: var(--red); }
    .step.skipped .step-status, .step.pending .step-status { color: var(--yellow); }
    /* raw json */
    .raw { margin-top: 22px; }
    .raw summary { cursor: pointer; padding: 10px 14px; background: var(--bg-2); border: 1px solid var(--border); border-radius: 10px; font-size: 13px; color: var(--text-1); list-style: none; }
    .raw summary::-webkit-details-marker { display: none; }
    .raw summary::before { content: "▶ "; color: var(--text-2); font-size: 10px; }
    .raw[open] summary::before { content: "▼ "; }
    .raw pre { margin: 12px 0 0; padding: 16px; background: rgba(0, 0, 0, 0.4); border: 1px solid var(--border); border-radius: 10px; overflow-x: auto; font-size: 11px; line-height: 1.5; color: var(--text-1); font-family: ui-monospace, SFMono-Regular, "Menlo", monospace; max-height: 600px; overflow-y: auto; }
    /* lightbox */
    .lightbox { position: fixed; inset: 0; background: rgba(0, 0, 0, 0.92); display: none; align-items: center; justify-content: center; z-index: 1000; cursor: zoom-out; padding: 40px; }
    .lightbox.active { display: flex; }
    .lightbox img { max-width: 100%; max-height: 100%; }
    /* warnings */
    .warnings { padding: 14px 18px; background: rgba(251, 146, 60, 0.08); border: 1px solid rgba(251, 146, 60, 0.32); border-radius: 12px; margin-bottom: 18px; font-size: 13px; color: #ffd9b3; }
    .warnings strong { color: var(--orange); }
    .warnings ul { margin: 6px 0 0; padding-left: 18px; }
    .empty-card { padding: 30px; text-align: center; color: var(--text-2); font-size: 13px; }
    .loading { padding: 100px; text-align: center; color: var(--text-2); }
    .loading::after { content: ""; display: block; width: 24px; height: 24px; border: 3px solid var(--bg-3); border-top-color: var(--accent); border-radius: 50%; margin: 14px auto 0; animation: spin 0.8s linear infinite; }
    @keyframes spin { to { transform: rotate(360deg); } }
  </style>
</head>
<body>
  <div class="app" id="app">
    <div class="loading">Carregando produto...</div>
  </div>
  <div class="lightbox" id="lightbox" onclick="this.classList.remove('active')"><img id="lightbox-img" alt="" /></div>

<script>
(() => {
  const params = new URLSearchParams(location.search);
  const apiKey = params.get('api_key') || params.get('key') || '';
  if (!apiKey) {
    document.body.innerHTML = '<div style="padding:60px;text-align:center;color:#f4f7ff;background:#060812;min-height:100vh;font-family:sans-serif;">Acesso autenticado: passe ?key=&lt;WORKER_API_KEY&gt; na URL.</div>';
    return;
  }
  const m = location.pathname.match(/\\/dashboard\\/product\\/(.+?)\\/?$/);
  if (!m) {
    document.body.innerHTML = '<div style="padding:60px;text-align:center;color:#f87171;">SKU n\\u00e3o informado na URL.</div>';
    return;
  }
  const sku = decodeURIComponent(m[1]);

  function esc(s) { return String(s ?? '').replace(/[&<>"']/g, ch => ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;' }[ch])); }
  function fmtMoney(n) {
    if (n == null || n === '') return '—';
    const v = typeof n === 'string' ? Number(n) : n;
    if (!Number.isFinite(v)) return '—';
    return 'R$ ' + v.toFixed(2).replace('.', ',');
  }
  function fmtDate(s) { if (!s) return '—'; try { return new Date(s).toLocaleString('pt-BR'); } catch { return s; } }
  function fmtNum(n) { if (n == null) return '—'; return new Intl.NumberFormat('pt-BR').format(n); }
  function pillClass(s) { return String(s ?? '').toLowerCase().replace(/[^a-z]/g, ''); }

  function videoEmbed(url) {
    const m = String(url).match(/(?:v=|embed\\/|youtu\\.be\\/)([\\w-]{6,})/);
    if (!m) return null;
    return 'https://www.youtube.com/embed/' + m[1];
  }

  function renderGallery(images) {
    if (!images || !images.length) return '<div class="gallery"><div class="empty">Sem imagens.</div></div>';
    const main = images[0];
    return [
      '<div class="gallery">',
        '<div class="main-img" onclick="openLightbox(this.querySelector(\\'img\\').src)"><img id="main-img" src="' + esc(main.src) + '" alt="" /></div>',
        '<div class="thumbs">',
          images.map((img, i) => '<div class="thumb' + (i === 0 ? ' active' : '') + '" onclick="selectImage(' + i + ', \\'' + esc(img.src) + '\\')"><img src="' + esc(img.src) + '" alt="" /></div>').join(''),
        '</div>',
      '</div>'
    ].join('');
  }

  function renderHeader(p, m) {
    const stockBadge = m.stock_status
      ? '<span class="badge ' + esc(pillClass(m.stock_status)) + '">' + esc(m.stock_status) + '</span>'
      : '';
    const statusBadge = m.status
      ? '<span class="badge ' + esc(pillClass(m.status)) + '">' + esc(m.status) + '</span>'
      : '';
    const typeBadge = '<span class="badge ' + esc(m.type || 'simple') + '">' + esc(m.type || 'simple') + '</span>';

    // Identity row: same SKU across loja / ERP / Woo (Woo is set after push).
    //   Loja: detected_sku from /api/product-specific-data (Código do produto).
    //   ERP : codigo field from the Space Informática product response.
    //   Woo : woo_product_id assigned after a successful push.
    const lojaSku = m.sku || '—';
    const erpData = p && p.erp && p.erp.data;
    let erpCodigo = null;
    if (erpData && typeof erpData === 'object') {
      // Space ERP wraps the actual product under produtos[0].
      const list = erpData.produtos;
      const node = Array.isArray(list) && list.length > 0 ? list[0] : (erpData.produto || erpData.Produto || erpData);
      if (node && (node.codigo != null || node.Codigo != null || node.code != null)) {
        erpCodigo = String(node.codigo ?? node.Codigo ?? node.code);
      }
    }
    const wooId = p && p.woo && p.woo.product_id ? String(p.woo.product_id) : null;
    const erpStatus = p && p.erp && p.erp.status;
    const wooStatus = p && p.woo && p.woo.status;
    const erpDisplay = erpCodigo
      ? '<span class="id-value">' + esc(erpCodigo) + '</span>'
      : '<span class="id-value empty">' + (erpStatus === 'skipped' ? 'ERP não configurado' : (erpStatus || '—')) + '</span>';
    const wooDisplay = wooId
      ? '<span class="id-value">' + esc(wooId) + '</span>'
      : '<span class="id-value empty">' + (wooStatus === 'pending' ? 'aguardando push' : (wooStatus || '—')) + '</span>';

    return [
      '<div class="topbar">',
        '<a class="back" href="/dashboard?key=' + encodeURIComponent(apiKey) + '">← voltar ao dashboard</a>',
        '<div style="font-size:12px;color:var(--text-2)">SKU <strong style="color:var(--text-0);font-family:ui-monospace,monospace">' + esc(lojaSku) + '</strong></div>',
      '</div>',
      '<div class="header">',
        '<div class="breadcrumb">' + (m.categories || []).map(c => esc(c.name)).join(' › ') + '</div>',
        '<h1>' + esc(m.name || m.sku) + '</h1>',
        '<div class="meta">',
          '<div class="badges">' + typeBadge + stockBadge + statusBadge + '</div>',
          (m.brand ? '<span class="sub">Marca <strong>' + esc(m.brand) + '</strong></span>' : ''),
          (m.short_description ? '<span class="sub">' + esc(m.short_description) + '</span>' : ''),
          (m.source_url ? '<span class="sub"><a href="' + esc(m.source_url) + '" target="_blank">ver na loja ↗</a></span>' : ''),
        '</div>',
        '<div class="id-row">',
          '<div class="id-cell"><span class="id-label">SKU · Loja</span><span class="id-value">' + esc(lojaSku) + '</span></div>',
          '<div class="id-sep"></div>',
          '<div class="id-cell"><span class="id-label">SKU · ERP</span>' + erpDisplay + '</div>',
          '<div class="id-sep"></div>',
          '<div class="id-cell"><span class="id-label">ID · Woo</span>' + wooDisplay + '</div>',
        '</div>',
      '</div>'
    ].join('');
  }

  function renderSpecs(m) {
    const dims = m.dimensions || {};
    const dimsParts = [dims.length, dims.width, dims.height].filter(Boolean).join(' × ');
    return [
      '<div class="card"><div class="card-h">Pre\\u00e7o & estoque</div><div class="card-b">',
        '<div class="specs">',
          '<div class="spec"><div class="label">Pre\\u00e7o</div><div class="value big green">' + fmtMoney(m.regular_price) + '</div></div>',
          (m.sale_price ? '<div class="spec"><div class="label">Promo</div><div class="value big yellow">' + fmtMoney(m.sale_price) + '</div></div>' : '<div class="spec"><div class="label">Promo</div><div class="value">—</div></div>'),
          '<div class="spec"><div class="label">Estoque</div><div class="value big' + (m.stock_quantity > 0 ? ' green' : ' red') + '">' + (m.stock_quantity != null ? fmtNum(m.stock_quantity) + ' un' : '—') + '</div></div>',
          '<div class="spec"><div class="label">Status</div><div class="value">' + esc(m.stock_status || '—') + '</div></div>',
        '</div>',
      '</div></div>',
      '<div class="card"><div class="card-h">Dimens\\u00f5es & identificadores</div><div class="card-b">',
        '<div class="specs">',
          '<div class="spec"><div class="label">Peso (kg)</div><div class="value">' + esc(m.weight ?? '—') + '</div></div>',
          '<div class="spec"><div class="label">L \\u00d7 W \\u00d7 H (cm)</div><div class="value mono">' + (dimsParts || '—') + '</div></div>',
          '<div class="spec"><div class="label">EAN / Barcode</div><div class="value mono">' + esc(m.barcode || '—') + '</div></div>',
          '<div class="spec"><div class="label">C\\u00f3d. fornecedor</div><div class="value mono">' + esc(m.provider_code || '—') + '</div></div>',
        '</div>',
      '</div></div>'
    ].join('');
  }

  function renderCategories(m) {
    if (!m.categories || !m.categories.length) return '';
    return [
      '<div class="card"><div class="card-h">Categorias</div><div class="card-b">',
        '<div class="cat-crumb">',
          m.categories.map((c, i) => (i > 0 ? '<span class="sep">›</span>' : '') + '<span class="crumb">' + esc(c.name) + '</span>').join(''),
        '</div>',
      '</div></div>'
    ].join('');
  }

  function renderAttributes(m) {
    if (!m.attributes || !m.attributes.length) return '';
    const rows = m.attributes.map(a =>
      '<div class="attr"><span class="a-name">' + esc(a.name) + '</span><span class="a-value">' + (a.options || []).map(esc).join(', ') + '</span>' + (a.variation ? ' <span style="color:var(--accent-2);font-size:10px">(varia\\u00e7\\u00e3o)</span>' : '') + '</div>'
    ).join('');
    return '<div class="card"><div class="card-h">Atributos</div><div class="card-b"><div class="attrs">' + rows + '</div></div></div>';
  }

  function renderVariations(m) {
    if (!m.variations || !m.variations.length) return '';
    const rows = m.variations.map(v => [
      '<tr>',
        '<td>' + (v.image && v.image.src ? '<img src="' + esc(v.image.src) + '" alt="" onclick="openLightbox(this.src)" style="cursor:zoom-in" />' : '<div style="width:36px;height:36px;background:var(--bg-2);border-radius:6px"></div>') + '</td>',
        '<td><strong style="color:var(--text-0)">' + esc(v.name) + '</strong></td>',
        '<td class="mono" style="font-family:ui-monospace,monospace;color:var(--text-0)">' + esc(v.sku || '—') + '</td>',
        '<td class="mono" style="font-family:ui-monospace,monospace;font-size:11px">' + esc(v.barcode || '—') + '</td>',
        '<td>' + fmtMoney(v.regular_price) + '</td>',
        '<td>' + (v.stock_quantity != null ? fmtNum(v.stock_quantity) + ' un' : '—') + '</td>',
        '<td><span class="pill ' + esc(pillClass(v.stock_status)) + '">' + esc(v.stock_status || '—') + '</span></td>',
        '<td class="mono" style="font-family:ui-monospace,monospace;font-size:11px">' + esc(v.weight || '—') + ' kg</td>',
      '</tr>'
    ].join('')).join('');
    return [
      '<div class="card"><div class="card-h">Varia\\u00e7\\u00f5es <span style="color:var(--text-1)">(' + m.variations.length + ')</span></div>',
      '<div class="card-b" style="padding:0;overflow-x:auto">',
        '<table class="table">',
          '<thead><tr><th></th><th>Nome</th><th>SKU</th><th>EAN</th><th>Pre\\u00e7o</th><th>Estoque</th><th>Status</th><th>Peso</th></tr></thead>',
          '<tbody>' + rows + '</tbody>',
        '</table>',
      '</div></div>'
    ].join('');
  }

  function renderVideos(m) {
    if (!m.video_urls || !m.video_urls.length) return '';
    const items = m.video_urls.map(url => {
      const emb = videoEmbed(url);
      return emb
        ? '<div class="video"><iframe src="' + esc(emb) + '" allowfullscreen loading="lazy"></iframe></div>'
        : '<a class="pdf-link" href="' + esc(url) + '" target="_blank">' + esc(url) + ' ↗</a>';
    }).join('');
    return '<div class="card"><div class="card-h">V\\u00eddeos</div><div class="card-b"><div class="media-grid">' + items + '</div></div></div>';
  }

  function renderPdfs(m) {
    if (!m.pdf_urls || !m.pdf_urls.length) return '';
    const items = m.pdf_urls.map(p =>
      '<a class="pdf-link" href="' + esc(p.url) + '" target="_blank"><span class="pdf-name">📄 ' + esc(p.label) + '</span><span class="pdf-go">abrir ↗</span></a>'
    ).join('');
    return '<div class="card"><div class="card-h">PDFs / Manuais</div><div class="card-b"><div class="media-grid">' + items + '</div></div></div>';
  }

  function renderDescription(m) {
    if (!m.description) return '';
    return '<div class="card"><div class="card-h">Descri\\u00e7\\u00e3o</div><div class="card-b"><div class="desc-html">' + m.description + '</div></div></div>';
  }

  function renderTimeline(p) {
    const stages = [
      { key: 'scrape',   label: 'Scrape',  status: p.scrape && p.scrape.status,  when: p.scrape && p.scrape.updated_at },
      { key: 'erp',      label: 'ERP',     status: p.erp    && p.erp.status,     when: p.erp && p.erp.updated_at },
      { key: 'merge',    label: 'Merge',   status: p.merged ? 'ok' : 'pending',  when: p.merged_updated_at },
      { key: 'media',    label: 'Mídia',   status: 'inferido', when: null },
      { key: 'push',     label: 'Push Woo', status: p.woo && p.woo.status,       when: p.woo && p.woo.updated_at },
    ];
    return '<div class="card"><div class="card-h">Pipeline</div><div class="card-b"><div class="timeline">' +
      stages.map(s => [
        '<div class="step ' + esc(pillClass(s.status)) + '">',
          '<div class="step-name">' + esc(s.label) + '</div>',
          '<div class="step-status">' + esc(s.status || '—') + '</div>',
          '<div class="step-when">' + (s.when ? fmtDate(s.when) : '—') + '</div>',
        '</div>'
      ].join('')).join('') +
      '</div></div></div>';
  }

  function renderWarnings(m) {
    if (!m.warnings || !m.warnings.length) return '';
    return '<div class="warnings"><strong>Warnings (' + m.warnings.length + '):</strong><ul>' +
      m.warnings.map(w => '<li>' + esc(w) + '</li>').join('') +
      '</ul></div>';
  }

  function renderRaw(p) {
    return '<details class="raw"><summary>Raw JSON do D1 (scrape + erp + merged + woo)</summary><pre>' + esc(JSON.stringify(p, null, 2)) + '</pre></details>';
  }

  async function load() {
    const u = new URL(location.origin + '/products/' + encodeURIComponent(sku));
    u.searchParams.set('api_key', apiKey);
    let res;
    try {
      res = await fetch(u);
    } catch (e) {
      document.getElementById('app').innerHTML = '<div class="loading" style="color:var(--red)">Erro de rede: ' + esc(e.message) + '</div>';
      return;
    }
    if (!res.ok) {
      document.getElementById('app').innerHTML = '<div class="loading" style="color:var(--red)">HTTP ' + res.status + ' — produto n\\u00e3o encontrado.</div>';
      return;
    }
    const product = await res.json();
    let merged = (product.merged && typeof product.merged === 'object') ? product.merged : null;
    let isPreMerge = false;

    // Fallback: when the merge stage hasn't run yet but scrape did, build a
    // display-only "pseudo-merged" record so the preview still shows everything
    // the scraper captured (gallery, price, stock, variations, videos, PDFs).
    if (!merged && product.scrape && product.scrape.data) {
      const s = product.scrape.data;
      const dims = s.dimensions || {};
      const isVar = s.type === 'variable' && Array.isArray(s.variations) && s.variations.length > 0;

      // Aggregate parent price/stock from variations (mirrors the real merge.ts logic).
      let parentPrice = s.price || null;
      let parentStockQty = s.stock_qty != null ? s.stock_qty : null;
      let parentStockStatus = s.stock_status === 'in_stock' ? 'instock' : s.stock_status === 'out_of_stock' ? 'outofstock' : null;
      if (isVar) {
        let min = null;
        let total = 0;
        let anyInStock = false;
        for (const v of s.variations) {
          if (v.price && v.stock_status !== 'out_of_stock') {
            const n = Number(v.price);
            if (Number.isFinite(n) && (min == null || n < min)) min = n;
          }
          if (v.stock_status !== 'out_of_stock' && v.stock_qty != null) total += v.stock_qty;
          if (v.stock_status === 'in_stock' || (v.stock_qty || 0) > 0) anyInStock = true;
        }
        if (parentPrice == null && min != null) parentPrice = min.toFixed(2);
        if (parentStockQty == null) parentStockQty = total || null;
        if (parentStockStatus == null) parentStockStatus = anyInStock ? 'instock' : 'outofstock';
      }

      merged = {
        sku: s.detected_sku || product.sku,
        type: s.type || 'simple',
        name: s.title || product.sku,
        slug: s.slug,
        status: null,
        description: s.description_html || s.description || null,
        short_description: s.short_description || null,
        brand: s.brand || null,
        categories: (s.category || []).map(name => ({ name })),
        images: (s.images || []).map(img => ({ src: img.src || img })),
        regular_price: parentPrice,
        sale_price: null,
        stock_quantity: parentStockQty,
        stock_status: parentStockStatus,
        weight: dims.weight != null ? String(dims.weight) : null,
        dimensions: {
          length: dims.length != null ? String(dims.length) : '',
          width: dims.width != null ? String(dims.width) : '',
          height: dims.height != null ? String(dims.height) : '',
        },
        barcode: s.barcode || null,
        provider_code: s.provider_code || null,
        attributes: s.brand ? [{ name: 'Marca', options: [s.brand], variation: false, visible: true }] : [],
        variations: (s.variations || []).map(v => {
          // Variation dims fall back to parent field-by-field. 0 is treated as missing.
          const vd = v.dimensions || {};
          const fbd = (c, p) => (c != null && c !== 0) ? c : (p != null && p !== 0 ? p : null);
          const w = fbd(vd.weight, dims.weight);
          const lg = fbd(vd.length, dims.length);
          const wd = fbd(vd.width, dims.width);
          const hg = fbd(vd.height, dims.height);
          return {
            name: v.name,
            sku: v.sku,
            provider_code: v.provider_code,
            barcode: v.barcode,
            regular_price: v.price,
            stock_quantity: v.stock_qty,
            stock_status: v.stock_status === 'in_stock' ? 'instock' : v.stock_status === 'out_of_stock' ? 'outofstock' : null,
            weight: w != null ? String(w) : null,
            dimensions: {
              length: lg != null ? String(lg) : '',
              width: wd != null ? String(wd) : '',
              height: hg != null ? String(hg) : '',
            },
            image: v.images && v.images[0] ? { src: v.images[0].src || v.images[0] } : null,
          };
        }),
        video_urls: s.video_urls || [],
        pdf_urls: s.pdf_urls || [],
        meta_data: [],
        warnings: [],
        source_url: s.url || product.source_url,
      };
      isPreMerge = true;
    }

    // Build images: parent gallery → variations[].image (when not in parent already) → meta_data video extras
    const galleryImages = [];
    const seen = new Set();
    if (merged && merged.images) {
      for (const im of merged.images) {
        if (im && im.src && !seen.has(im.src)) { seen.add(im.src); galleryImages.push(im); }
      }
    }
    if (merged && merged.variations) {
      for (const v of merged.variations) {
        if (v.image && v.image.src && !seen.has(v.image.src)) { seen.add(v.image.src); galleryImages.push(v.image); }
      }
    }

    const preBanner = isPreMerge
      ? '<div class="warnings"><strong>⚠ Pré-merge:</strong> mostrando apenas dados do scrape da loja. ERP/merge ainda não rodaram para este SKU.</div>'
      : '';

    const html = !merged
      ? '<div class="topbar"><a class="back" href="/dashboard?key=' + encodeURIComponent(apiKey) + '">← voltar</a></div><div class="header"><h1>' + esc(product.sku) + '</h1><div class="meta"><span class="sub">Sem dados ainda. Scrape: <strong>' + esc((product.scrape && product.scrape.status) || 'pending') + '</strong></span></div></div>' + renderTimeline(product) + renderRaw(product)
      : [
        renderHeader(product, merged),
        preBanner,
        renderWarnings(merged),
        '<div class="split">',
          '<div class="col">',
            renderGallery(galleryImages),
            renderTimeline(product),
          '</div>',
          '<div class="col">',
            renderSpecs(merged),
            renderCategories(merged),
            renderAttributes(merged),
            renderVariations(merged),
            renderVideos(merged),
            renderPdfs(merged),
            renderDescription(merged),
          '</div>',
        '</div>',
        renderRaw(product)
      ].join('');

    document.getElementById('app').innerHTML = html;
    document.title = (merged && merged.name ? merged.name + ' · ' : '') + sku;
  }

  window.selectImage = (i, src) => {
    document.getElementById('main-img').src = src;
    document.querySelectorAll('.thumb').forEach((t, j) => t.classList.toggle('active', i === j));
  };
  window.openLightbox = (src) => {
    document.getElementById('lightbox-img').src = src;
    document.getElementById('lightbox').classList.add('active');
  };

  load();
})();
</script>
</body>
</html>`;
