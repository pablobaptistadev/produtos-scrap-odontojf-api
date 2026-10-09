import { describe, it, expect } from "vitest";
import { decodeBody } from "../src/erp/decode";
import { erpText, mergeScrapeAndErp } from "../src/sync/merge";

// "PINÇA GOIVA 6B" e "LASER PORTÁTIL" como o ERP manda: Latin-1, não UTF-8
const latin1 = (s: string) => Uint8Array.from([...s].map((c) => c.charCodeAt(0)));

describe("decodeBody — resposta do ERP", () => {
  it("lê Latin-1 sem trocar acento por �", () => {
    const body = decodeBody(latin1('{"descricao":"PINÇA GOIVA 6B","marca":"LASER PORTÁTIL"}'));
    expect(body).toContain("PINÇA GOIVA 6B");
    expect(body).toContain("LASER PORTÁTIL");
    expect(body).not.toContain("�");
  });

  it("continua lendo UTF-8 de verdade", () => {
    const body = decodeBody(new TextEncoder().encode('{"descricao":"BOTÃO LINGUAL"}'));
    expect(JSON.parse(body).descricao).toBe("BOTÃO LINGUAL");
  });
});

describe("erpText", () => {
  it("troca NBSP do ERP por espaço", () => {
    expect(erpText("SERINGA 20ML C/AG MEDIX  ")).toBe("SERINGA 20ML C/AG MEDIX");
  });
  it("texto já estragado (com �) é descartado", () => {
    expect(erpText("PIN�A GOIVA 6B")).toBeNull();
  });
});

describe("mergeScrapeAndErp — nome do ERP estragado", () => {
  const scrape: any = {
    url: "https://shop/p", slug: "p", type: "simple", id: "X", title: "Alveolótomo Pinça Goiva Curva 12cm",
    brand: null, category: [], short_description: null, description: "desc da origem", description_html: null,
    images: [], description_images: [], stock_status: "in_stock", raw_meta: {}, detected_sku: null, barcode: null,
    provider_code: null, dimensions: { weight: null, length: null, width: null, height: null }, price: null,
    price_text: null, installments: null, stock_qty: null, variations: [], video_urls: [], pdf_urls: [],
    fetched_at: "2026-10-09T00:00:00Z", status_code: 200, api_enriched: false,
  };
  it("usa o título da origem em vez de publicar �", () => {
    const merged = mergeScrapeAndErp({ sku: "20515", scrape, erp: { codigo: 20515, descricao: "PIN�A GOIVA 6B" } });
    expect(merged.name).toBe("Alveolótomo Pinça Goiva Curva 12cm");
  });
  it("nome do ERP bem decodificado continua valendo", () => {
    const merged = mergeScrapeAndErp({ sku: "20515", scrape, erp: { codigo: 20515, descricao: "PINÇA GOIVA 6B" } });
    expect(merged.name).toBe("PINÇA GOIVA 6B");
  });
});
