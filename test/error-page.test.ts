import { afterEach, describe, expect, it, vi } from "vitest";
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
