import { afterEach, describe, expect, it, vi } from "vitest";
import { readFileSync } from "node:fs";
import { join } from "node:path";
import { looksLikeErrorPageTitle } from "../src/core";
import { fetchProductPage } from "../src/scraper/product-page";

// Em 01/10 a origem devolveu 403 ao Worker e 86 produtos chegaram à loja com
// nome "403: Forbidden" ou "Página não encontrada". Estes testes travam as duas
// portas por onde isso entrou: o detector de título e o fetch do scraper.

describe("looksLikeErrorPageTitle", () => {
  it.each([
    "403: Forbidden",
    "404: Not Found",
    "Página não encontrada",
    "Pagina nao encontrada",
    "Access Denied",
    "Attention Required! | Cloudflare",
    "Just a moment...",
    "502 Bad Gateway",
    "503 Service Unavailable",
    "429 Too Many Requests",
    "Error",
    "",
    "   ",
  ])("recusa %j", (title) => {
    expect(looksLikeErrorPageTitle(title)).toBe(true);
  });

  it.each([
    "Fórceps Adulto N°150",
    "Sonda Exploradora Dupla Lite 5",
    "Resina Filtek Z350 XT – A2B",
    "GESSO ESPECIAL TIPO IV ROSA 1KG SMILETONE",
    "Ponta Diamantada Esférica FG 3018",
    "Kit Lima Manual M 25mm Taper 05 - 6 unidades",
  ])("aceita %j", (title) => {
    expect(looksLikeErrorPageTitle(title)).toBe(false);
  });

  it("trata null e undefined como sem nome", () => {
    expect(looksLikeErrorPageTitle(null)).toBe(true);
    expect(looksLikeErrorPageTitle(undefined)).toBe(true);
  });
});

describe("fetchProductPage recusa o que não é produto", () => {
  const env = { REQUEST_TIMEOUT_MS: "5000" } as any;
  const url = "https://dentalodontocirurgicajf.com.br/sonda-exploradora-dupla-lite-5";

  afterEach(() => {
    vi.unstubAllGlobals();
  });

  function stubResponse(status: number, body: string) {
    vi.stubGlobal(
      "fetch",
      vi.fn(async () => new Response(body, { status, headers: { "content-type": "text/html" } })),
    );
  }

  it("lança em 403 em vez de fazer parse da página de bloqueio", async () => {
    stubResponse(403, "<html><head><title>403: Forbidden</title></head><body>blocked</body></html>");
    await expect(fetchProductPage(env, url)).rejects.toThrow(/HTTP 403/);
  });

  it("lança em 404", async () => {
    stubResponse(404, "<html><head><title>Página não encontrada</title></head></html>");
    await expect(fetchProductPage(env, url)).rejects.toThrow(/HTTP 404/);
  });

  it("lança em 200 sem produto no __NEXT_DATA__", async () => {
    stubResponse(200, "<html><head><title>Algo</title></head><body>sem next data</body></html>");
    await expect(fetchProductPage(env, url)).rejects.toThrow(/sem dados de produto/);
  });
});

describe("fetchProductPage não salva variação sem código", () => {
  // Mesma rajada de 01/10: a página do produto veio, mas a origem bloqueou parte
  // das consultas de /api/product-specific-data. As variações ficaram sem SKU,
  // o push foi assim mesmo e o plugin apagou as variações de 24 produtos.
  const env = {
    REQUEST_TIMEOUT_MS: "5000",
    SCRAPE_BASE_URL: "https://dentalodontocirurgicajf.com.br",
  } as any;
  const url = "https://dentalodontocirurgicajf.com.br/resina-elora-aps-4g-fgm";
  const html = readFileSync(join(__dirname, "fixtures", "resina-elora-variable.html"), "utf-8");
  const bloqueada = "M0drjMmXdoH3Sli7xSxI";

  afterEach(() => {
    vi.unstubAllGlobals();
  });

  function stubOrigem(specific: (id: string, call: number) => Response) {
    const calls = new Map<string, number>();
    const fetchMock = vi.fn(async (input: RequestInfo | URL) => {
      const u = new URL(String(input));
      if (!u.pathname.startsWith("/api/product-specific-data")) {
        return new Response(html, { status: 200, headers: { "content-type": "text/html" } });
      }
      const id = u.searchParams.get("productId")!;
      const n = (calls.get(id) ?? 0) + 1;
      calls.set(id, n);
      return specific(id, n);
    });
    vi.stubGlobal("fetch", fetchMock);
    return fetchMock;
  }
  const json = (body: unknown, status = 200) =>
    new Response(JSON.stringify(body), { status, headers: { "content-type": "application/json" } });

  it("falha o scrape inteiro se uma variação levar 403", async () => {
    stubOrigem((id) => (id === bloqueada ? json({ error: "blocked" }, 403) : json({ id, internalId: `C-${id}` })));
    await expect(fetchProductPage(env, url)).rejects.toThrow(/dados específicos da origem incompletos/);
  });

  it("falha se uma variação vier com corpo vazio", async () => {
    stubOrigem((id) => (id === bloqueada ? json(null) : json({ id, internalId: `C-${id}` })));
    await expect(fetchProductPage(env, url)).rejects.toThrow(/incompletos/);
  });

  it("tenta de novo um 403 passageiro e salva com todos os códigos", async () => {
    stubOrigem((id, call) =>
      id === bloqueada && call === 1 ? json({ error: "blocked" }, 403) : json({ id, internalId: `C-${id}` }),
    );
    const r = await fetchProductPage(env, url);
    expect(r.variations.length).toBe(10);
    expect(r.variations.every((v) => v.sku === `C-${v.id}`)).toBe(true);
    expect(r.api_enriched).toBe(true);
  });

  it("não dispara mais de 4 consultas ao mesmo tempo", async () => {
    let abertas = 0;
    let pico = 0;
    vi.stubGlobal(
      "fetch",
      vi.fn(async (input: RequestInfo | URL) => {
        const u = new URL(String(input));
        if (!u.pathname.startsWith("/api/product-specific-data")) {
          return new Response(html, { status: 200 });
        }
        abertas++;
        pico = Math.max(pico, abertas);
        await new Promise((r) => setTimeout(r, 5));
        abertas--;
        const id = u.searchParams.get("productId")!;
        return json({ id, internalId: `C-${id}` });
      }),
    );
    await fetchProductPage(env, url);
    expect(pico).toBeLessThanOrEqual(4);
  });
});
