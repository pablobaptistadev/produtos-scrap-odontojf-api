import type { Hono } from "hono";
import type { AppEnv } from "../env";

/**
 * Server-rendered HTML dashboard mirroring the Fullbai Observability Center
 * layout. Single-file SPA: HTML + CSS + JS inlined so it ships with the worker
 * (no build step). Auth uses the same api-key middleware as the rest of the
 * worker — pass ?api_key=<WORKER_API_KEY> in the URL.
 *
 * The dashboard pulls data from JSON endpoints under /dashboard/api/*. Those
 * endpoints live in dashboard-api.ts so this file stays static.
 */
export function registerDashboardRoutes(app: Hono<AppEnv>): void {
  app.get("/dashboard", (c) => {
    return new Response(DASHBOARD_HTML, {
      headers: { "content-type": "text/html; charset=utf-8" },
    });
  });
}

const DASHBOARD_HTML = /* html */ `<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>OdontoJF Observability Center</title>
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
      --pill-bg: rgba(255, 199, 89, 0.12);
      --pill-fg: #fbbf24;
    }
    * { box-sizing: border-box; }
    html, body { margin: 0; padding: 0; min-height: 100vh; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Inter, sans-serif; color: var(--text-0); background: radial-gradient(1200px 800px at -10% -10%, #1b2152 0%, transparent 60%), radial-gradient(900px 700px at 110% 0%, #2c1c5b 0%, transparent 50%), var(--bg-0); }
    a { color: inherit; text-decoration: none; }
    button { font: inherit; color: inherit; cursor: pointer; }
    .app { max-width: 1680px; margin: 0 auto; padding: 28px 32px; }
    /* header */
    .topbar { display: flex; align-items: flex-start; justify-content: space-between; gap: 24px; margin-bottom: 24px; }
    .topbar h1 { font-size: 30px; line-height: 1.15; margin: 0 0 6px; letter-spacing: -0.5px; font-weight: 700; }
    .topbar .subtitle { color: var(--text-1); font-size: 14px; }
    .topbar .actions { display: flex; gap: 10px; flex-wrap: wrap; }
    .btn { background: var(--bg-2); border: 1px solid var(--border); color: var(--text-0); padding: 10px 16px; border-radius: 12px; font-size: 13px; font-weight: 500; transition: all 120ms ease; }
    .btn:hover { border-color: var(--border-strong); background: var(--bg-3); }
    .btn.primary { background: rgba(56, 189, 248, 0.12); color: var(--accent); border-color: rgba(56, 189, 248, 0.32); }
    /* period tabs */
    .periods { display: flex; gap: 8px; margin: 18px 0 22px; align-items: center; flex-wrap: wrap; }
    .period { padding: 8px 18px; border-radius: 999px; background: var(--bg-2); border: 1px solid var(--border); font-size: 13px; color: var(--text-1); }
    .period.active { background: rgba(56, 189, 248, 0.14); color: var(--accent); border-color: rgba(56, 189, 248, 0.32); }
    .period-meta { color: var(--text-2); font-size: 12px; margin-left: 8px; }
    /* stats grid */
    .stats { display: grid; grid-template-columns: repeat(6, minmax(0, 1fr)); gap: 16px; margin-bottom: 24px; }
    @media (max-width: 1280px) { .stats { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
    .stat { background: linear-gradient(180deg, rgba(20, 26, 60, 0.7) 0%, rgba(11, 15, 38, 0.7) 100%); border: 1px solid var(--border); border-radius: 18px; padding: 18px 20px; min-height: 120px; display: flex; flex-direction: column; justify-content: space-between; backdrop-filter: blur(8px); }
    .stat .label { font-size: 11px; letter-spacing: 1.5px; text-transform: uppercase; color: var(--text-2); font-weight: 600; }
    .stat .value { font-size: 36px; font-weight: 700; letter-spacing: -1px; line-height: 1; margin: 6px 0 4px; }
    .stat .hint { font-size: 12px; color: var(--text-2); }
    .stat.exec .value { color: var(--accent); }
    .stat.ok .value { color: var(--green); }
    .stat.fail .value { color: var(--red); }
    .stat.fail { border-color: rgba(248, 113, 113, 0.4); }
    .stat.rate .value { color: var(--green); }
    .stat.time .value { color: var(--accent-2); }
    .stat.queue .value { color: var(--yellow); }
    /* sub tabs */
    .subtabs { display: flex; gap: 8px; margin-bottom: 16px; flex-wrap: wrap; }
    .subtab { padding: 7px 16px; border-radius: 999px; background: var(--bg-2); border: 1px solid var(--border); font-size: 13px; color: var(--text-1); }
    .subtab.active { background: rgba(56, 189, 248, 0.14); color: var(--accent); border-color: rgba(56, 189, 248, 0.32); }
    /* filters */
    .filters { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 12px; margin-bottom: 16px; }
    @media (max-width: 1100px) { .filters { grid-template-columns: repeat(2, 1fr); } }
    .filters select, .filters input { background: var(--bg-2); border: 1px solid var(--border); color: var(--text-0); padding: 9px 12px; border-radius: 10px; font-size: 13px; outline: none; width: 100%; }
    .filters select:focus, .filters input:focus { border-color: rgba(56, 189, 248, 0.5); }
    /* main split */
    .split { display: grid; grid-template-columns: 1fr 460px; gap: 16px; }
    @media (max-width: 1280px) { .split { grid-template-columns: 1fr; } }
    .panel { background: linear-gradient(180deg, rgba(15, 21, 48, 0.6) 0%, rgba(11, 15, 38, 0.6) 100%); border: 1px solid var(--border); border-radius: 18px; overflow: hidden; backdrop-filter: blur(8px); }
    .panel-header { padding: 14px 18px; border-bottom: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between; font-size: 12px; color: var(--text-2); text-transform: uppercase; letter-spacing: 0.7px; }
    /* list */
    .list { max-height: calc(100vh - 460px); overflow-y: auto; }
    .list table { width: 100%; border-collapse: collapse; font-size: 13px; }
    .list thead th { position: sticky; top: 0; background: var(--bg-1); padding: 10px 12px; text-align: left; font-size: 11px; letter-spacing: 0.7px; text-transform: uppercase; color: var(--text-2); border-bottom: 1px solid var(--border); }
    .list tbody tr { border-bottom: 1px solid var(--border); cursor: pointer; transition: background 80ms; }
    .list tbody tr:hover { background: rgba(56, 189, 248, 0.05); }
    .list tbody tr.selected { background: rgba(56, 189, 248, 0.13); border-left: 3px solid var(--accent); }
    .list td { padding: 11px 12px; vertical-align: middle; color: var(--text-1); }
    .list td.mono { font-family: ui-monospace, SFMono-Regular, "Menlo", monospace; font-size: 12px; color: var(--text-0); }
    .pill { display: inline-block; padding: 3px 10px; border-radius: 999px; font-size: 11px; font-weight: 600; letter-spacing: 0.3px; }
    .pill.passed, .pill.done, .pill.ok, .pill.instock { background: rgba(74, 222, 128, 0.15); color: var(--green); }
    .pill.pending { background: rgba(56, 189, 248, 0.15); color: var(--accent); }
    .pill.processing { background: rgba(129, 140, 248, 0.18); color: var(--accent-2); }
    .pill.failed, .pill.error, .pill.dead { background: rgba(248, 113, 113, 0.18); color: var(--red); }
    .pill.warn { background: rgba(251, 146, 60, 0.18); color: var(--orange); }
    .pill.skipped { background: rgba(120, 140, 220, 0.15); color: var(--text-1); }
    .pill.info { background: rgba(184, 197, 232, 0.15); color: var(--text-1); }
    /* detail */
    .detail { max-height: calc(100vh - 240px); overflow-y: auto; padding: 18px 22px; }
    .detail .field { margin-bottom: 14px; }
    .detail .field-label { font-size: 11px; letter-spacing: 0.7px; text-transform: uppercase; color: var(--text-2); margin-bottom: 4px; font-weight: 600; }
    .detail .field-value { font-size: 14px; color: var(--text-0); word-break: break-word; }
    .detail .field-value.mono { font-family: ui-monospace, SFMono-Regular, "Menlo", monospace; font-size: 12px; }
    .detail pre { background: rgba(0, 0, 0, 0.35); padding: 12px 14px; border-radius: 10px; overflow-x: auto; font-size: 12px; line-height: 1.5; color: var(--text-1); border: 1px solid var(--border); margin: 0; }
    .empty { padding: 60px 30px; text-align: center; color: var(--text-2); font-size: 14px; }
    .pagination { display: flex; align-items: center; gap: 10px; padding: 12px 18px; border-top: 1px solid var(--border); }
    .page-btn { padding: 6px 14px; border-radius: 8px; background: var(--bg-2); border: 1px solid var(--border); font-size: 12px; }
    .page-btn:disabled { opacity: 0.4; cursor: not-allowed; }
    .auto-refresh-status { color: var(--text-2); font-size: 12px; }
    .auto-refresh-status .dot { display: inline-block; width: 6px; height: 6px; border-radius: 50%; background: var(--green); vertical-align: middle; margin-right: 6px; }
    .auto-refresh-status .dot.off { background: var(--text-2); }
    /* loading shimmer */
    .stat.loading .value { opacity: 0.3; }
    .skeleton { display: inline-block; height: 14px; width: 80px; background: linear-gradient(90deg, var(--bg-2) 0%, var(--bg-3) 50%, var(--bg-2) 100%); background-size: 200% 100%; animation: shimmer 1.5s infinite; border-radius: 4px; }
    @keyframes shimmer { 0% { background-position: 200% 0; } 100% { background-position: -200% 0; } }
  </style>
</head>
<body>
  <div class="app">
    <header class="topbar">
      <div>
        <h1>OdontoJF Observability Center</h1>
        <div class="subtitle">Pipeline scrape → ERP → merge → media → push · monitoramento e replay</div>
      </div>
      <div class="actions">
        <span class="auto-refresh-status"><span class="dot" id="auto-dot"></span><span id="auto-text">auto 10s</span></span>
        <button class="btn primary" id="refresh-now">Refresh now</button>
      </div>
    </header>

    <nav class="periods">
      <button class="period active" data-period="today">Hoje</button>
      <button class="period" data-period="7d">7 dias</button>
      <button class="period" data-period="30d">Este mês</button>
      <button class="period" data-period="90d">Trimestre</button>
      <button class="period" data-period="365d">Este ano</button>
      <span class="period-meta" id="last-update">atualizado --:--:--</span>
    </nav>

    <section class="stats">
      <div class="stat exec"><div class="label">Execuções</div><div class="value" id="stat-exec">—</div><div class="hint" id="stat-exec-hint">no período</div></div>
      <div class="stat ok"><div class="label">Concluídas</div><div class="value" id="stat-ok">—</div><div class="hint" id="stat-ok-hint">com sucesso</div></div>
      <div class="stat fail"><div class="label">Falhas</div><div class="value" id="stat-fail">—</div><div class="hint" id="stat-fail-hint">erros + DLQ</div></div>
      <div class="stat rate"><div class="label">Taxa de falha</div><div class="value" id="stat-rate">—</div><div class="hint">% do total</div></div>
      <div class="stat time"><div class="label">Tempo médio</div><div class="value" id="stat-time">—</div><div class="hint">tempo execução</div></div>
      <div class="stat queue"><div class="label">Fila atual</div><div class="value" id="stat-queue">—</div><div class="hint" id="stat-queue-hint">pendentes</div></div>
    </section>

    <nav class="subtabs">
      <button class="subtab active" data-tab="queue">Fila (sync_queue)</button>
      <button class="subtab" data-tab="products">Produtos</button>
      <button class="subtab" data-tab="events">Eventos sistema</button>
    </nav>

    <section class="filters">
      <select id="filter-status">
        <option value="">status (todos)</option>
        <option value="pending">pending</option>
        <option value="processing">processing</option>
        <option value="done">done</option>
        <option value="failed">failed</option>
        <option value="dead">dead</option>
      </select>
      <select id="filter-stage">
        <option value="">stage (todos)</option>
        <option value="rebuild">rebuild</option>
        <option value="scrape">scrape</option>
        <option value="erp">erp</option>
        <option value="merge">merge</option>
        <option value="media">media</option>
        <option value="push">push</option>
      </select>
      <input id="filter-sku" placeholder="sku contém..." />
      <select id="filter-limit">
        <option value="50">50 itens</option>
        <option value="80" selected>80 itens</option>
        <option value="200">200 itens</option>
      </select>
    </section>

    <section class="split">
      <div class="panel">
        <div class="panel-header">
          <span id="list-title">sync_queue · <span id="list-count">--</span> exibidos</span>
          <span id="list-meta">atualizado --:--:--</span>
        </div>
        <div class="list" id="list-body"><div class="empty">Carregando...</div></div>
        <div class="pagination">
          <button class="page-btn" id="page-prev">←</button>
          <span id="page-info" style="font-size: 12px; color: var(--text-2);">página 1</span>
          <button class="page-btn" id="page-next">→</button>
        </div>
      </div>
      <aside class="panel">
        <div class="panel-header">
          <span>Detalhes</span>
          <span id="detail-id"></span>
        </div>
        <div class="detail" id="detail-body"><div class="empty">Selecione um item à esquerda</div></div>
      </aside>
    </section>
  </div>

<script>
(() => {
  const params = new URLSearchParams(location.search);
  const apiKey = params.get('api_key') || params.get('key') || '';
  if (!apiKey) {
    document.body.innerHTML = '<div style="padding:60px;text-align:center;font-family:sans-serif;color:#f4f7ff;background:#060812;min-height:100vh;">Acesso autenticado: passe ?api_key=&lt;WORKER_API_KEY&gt; na URL.</div>';
    return;
  }

  const state = {
    period: 'today',
    tab: 'queue',
    filters: { status: '', stage: '', sku: '', limit: 80 },
    page: 0,
    selected: null,
    autoRefresh: true,
  };

  function api(path, init = {}) {
    const u = new URL(location.origin + path);
    u.searchParams.set('api_key', apiKey);
    return fetch(u, init).then(r => {
      if (!r.ok) throw new Error('HTTP ' + r.status);
      return r.json();
    });
  }

  function fmtNumber(n) {
    if (n == null) return '--';
    return new Intl.NumberFormat('pt-BR').format(n);
  }
  function fmtMs(n) {
    if (n == null) return '--';
    return n >= 1000 ? (n / 1000).toFixed(2) + ' s' : Math.round(n) + ' ms';
  }
  function fmtPct(n) { return n == null ? '--' : (n * 100).toFixed(1) + '%'; }
  function fmtDate(s) { if (!s) return '--'; try { return new Date(s).toLocaleString('pt-BR'); } catch { return s; } }
  function nowTime() { return new Date().toLocaleTimeString('pt-BR'); }

  async function refresh() {
    document.getElementById('last-update').textContent = 'atualizado ' + nowTime();
    await Promise.all([loadStats(), loadList()]);
  }

  async function loadStats() {
    try {
      const data = await api('/dashboard/api/stats?period=' + state.period);
      document.getElementById('stat-exec').textContent = fmtNumber(data.execucoes);
      document.getElementById('stat-ok').textContent = fmtNumber(data.concluidas);
      document.getElementById('stat-fail').textContent = fmtNumber(data.falhas);
      document.getElementById('stat-rate').textContent = fmtPct(data.taxa_falha);
      document.getElementById('stat-time').textContent = fmtMs(data.tempo_medio_ms);
      document.getElementById('stat-queue').textContent = fmtNumber(data.fila_atual);
      document.getElementById('stat-queue-hint').textContent = (data.pending ?? 0) + ' pend. / ' + (data.processing ?? 0) + ' proc.';
      // recolour the rate based on value
      const rateEl = document.getElementById('stat-rate');
      const r = data.taxa_falha ?? 0;
      rateEl.style.color = r > 0.05 ? 'var(--red)' : r > 0.01 ? 'var(--orange)' : 'var(--green)';
    } catch (e) { console.error(e); }
  }

  async function loadList() {
    const body = document.getElementById('list-body');
    try {
      const offset = state.page * state.filters.limit;
      const u = '/dashboard/api/' + state.tab
        + '?status=' + encodeURIComponent(state.filters.status)
        + '&stage=' + encodeURIComponent(state.filters.stage)
        + '&sku=' + encodeURIComponent(state.filters.sku)
        + '&limit=' + state.filters.limit
        + '&offset=' + offset;
      const data = await api(u);
      document.getElementById('list-count').textContent = data.items.length + ' / ' + fmtNumber(data.total);
      document.getElementById('list-meta').textContent = 'atualizado ' + nowTime();
      body.innerHTML = '';
      if (!data.items.length) {
        body.innerHTML = '<div class="empty">Sem itens.</div>';
        return;
      }
      const table = document.createElement('table');
      const headers = data.headers;
      table.innerHTML = '<thead><tr>' + headers.map(h => '<th>' + h.label + '</th>').join('') + '</tr></thead><tbody></tbody>';
      const tbody = table.querySelector('tbody');
      data.items.forEach(item => {
        const tr = document.createElement('tr');
        tr.dataset.id = item._id;
        if (state.selected === item._id) tr.classList.add('selected');
        headers.forEach(h => {
          const td = document.createElement('td');
          const v = item[h.field];
          if (h.kind === 'pill') {
            const cls = String(v ?? '').toLowerCase();
            td.innerHTML = v ? '<span class="pill ' + cls + '">' + v + '</span>' : '<span class="pill">--</span>';
          } else if (h.kind === 'mono') {
            td.className = 'mono';
            td.textContent = v == null ? '--' : String(v);
          } else if (h.kind === 'time') {
            td.textContent = fmtMs(v);
          } else if (h.kind === 'date') {
            td.textContent = fmtDate(v);
          } else {
            td.textContent = v == null ? '--' : String(v);
          }
          tr.appendChild(td);
        });
        tr.addEventListener('click', (e) => {
          // For the products tab, single click selects + shows side detail;
          // double click (or cmd/ctrl-click) opens the full product preview page.
          if (state.tab === 'products') {
            const url = '/dashboard/product/' + encodeURIComponent(item._id) + '?key=' + encodeURIComponent(apiKey);
            if (e.detail >= 2 || e.metaKey || e.ctrlKey) { window.open(url, '_blank'); return; }
          }
          state.selected = item._id;
          loadDetail(item);
          document.querySelectorAll('.list tbody tr').forEach(r => r.classList.remove('selected'));
          tr.classList.add('selected');
        });
        if (state.tab === 'products') {
          // also add an explicit "abrir" link in the row's last cell so it's discoverable
          const openTd = document.createElement('td');
          openTd.style.textAlign = 'right';
          openTd.style.paddingRight = '14px';
          openTd.innerHTML = '<a href="/dashboard/product/' + encodeURIComponent(item._id) + '?key=' + encodeURIComponent(apiKey) + '" target="_blank" style="color:var(--accent);font-size:11px;text-decoration:none">abrir ↗</a>';
          openTd.addEventListener('click', (e) => e.stopPropagation());
          tr.appendChild(openTd);
        }
        tbody.appendChild(tr);
      });
      body.innerHTML = '';
      body.appendChild(table);
      document.getElementById('page-info').textContent = 'pág. ' + (state.page + 1) + ' · ' + fmtNumber(data.total) + ' total';
      document.getElementById('page-prev').disabled = state.page === 0;
      document.getElementById('page-next').disabled = (offset + data.items.length) >= data.total;
    } catch (e) {
      body.innerHTML = '<div class="empty">Erro: ' + e.message + '</div>';
    }
  }

  function loadDetail(item) {
    const body = document.getElementById('detail-body');
    document.getElementById('detail-id').textContent = '#' + item._id;
    body.innerHTML = '';
    const fields = item._detail || Object.entries(item).filter(([k]) => !k.startsWith('_'));
    fields.forEach(([key, value]) => {
      const block = document.createElement('div');
      block.className = 'field';
      const label = document.createElement('div');
      label.className = 'field-label';
      label.textContent = key;
      block.appendChild(label);
      const v = document.createElement('div');
      if (typeof value === 'object' && value !== null) {
        const pre = document.createElement('pre');
        pre.textContent = JSON.stringify(value, null, 2);
        block.appendChild(pre);
      } else {
        v.className = 'field-value' + (typeof value === 'string' && value.length < 80 ? '' : ' mono');
        v.textContent = value == null ? '--' : String(value);
        block.appendChild(v);
      }
      body.appendChild(block);
    });
  }

  // event wiring
  document.querySelectorAll('.period').forEach(el => {
    el.addEventListener('click', () => {
      document.querySelectorAll('.period').forEach(p => p.classList.remove('active'));
      el.classList.add('active');
      state.period = el.dataset.period;
      loadStats();
    });
  });
  document.querySelectorAll('.subtab').forEach(el => {
    el.addEventListener('click', () => {
      document.querySelectorAll('.subtab').forEach(p => p.classList.remove('active'));
      el.classList.add('active');
      state.tab = el.dataset.tab;
      state.page = 0;
      state.selected = null;
      loadList();
    });
  });
  ['filter-status', 'filter-stage', 'filter-sku', 'filter-limit'].forEach(id => {
    document.getElementById(id).addEventListener('change', () => {
      state.filters.status = document.getElementById('filter-status').value;
      state.filters.stage = document.getElementById('filter-stage').value;
      state.filters.sku = document.getElementById('filter-sku').value;
      state.filters.limit = parseInt(document.getElementById('filter-limit').value, 10);
      state.page = 0;
      loadList();
    });
  });
  document.getElementById('filter-sku').addEventListener('input', () => {
    clearTimeout(window._skuT);
    window._skuT = setTimeout(() => {
      state.filters.sku = document.getElementById('filter-sku').value;
      state.page = 0;
      loadList();
    }, 350);
  });
  document.getElementById('refresh-now').addEventListener('click', refresh);
  document.getElementById('page-prev').addEventListener('click', () => { state.page = Math.max(0, state.page - 1); loadList(); });
  document.getElementById('page-next').addEventListener('click', () => { state.page = state.page + 1; loadList(); });

  refresh();
  setInterval(() => { if (state.autoRefresh) refresh(); }, 10_000);
})();
</script>
</body>
</html>`;
