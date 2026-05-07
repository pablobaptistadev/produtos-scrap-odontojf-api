import { describe, it, expect } from "vitest";
import { mergeScrapeAndErp } from "../src/sync/merge";

describe("mergeScrapeAndErp", () => {
  const baseScrape: any = {
    url: "https://shop/foo",
    slug: "foo",
    type: "simple",
    id: "ID1",
    title: "Foo do Scraper",
    brand: "Brand-Scrape",
    category: ["Cat A", "Cat B"],
    short_description: null,
    description: "desc do scraper",
    description_html: null,
    images: [
      { src: "https://cdn.example/a.jpg", alt: null },
      { src: "https://cdn.example/b.jpg", alt: null },
    ],
    description_images: [],
    stock_status: "in_stock",
    raw_meta: {},
    detected_sku: null,
    barcode: null,
    provider_code: null,
    dimensions: { weight: null, length: null, width: null, height: null },
    price: null,
    price_text: null,
    installments: null,
    stock_qty: null,
    variations: [],
    video_urls: [],
    pdf_urls: [],
    fetched_at: "2026-05-02T00:00:00Z",
    status_code: 200,
    api_enriched: false,
  };

  it("uses ERP name and price, scraper images", () => {
    const merged = mergeScrapeAndErp({
      sku: "SKU1",
      scrape: baseScrape,
      erp: { nome: "Foo do ERP", preco: "199.90", estoque: 5, marca: "Brand-ERP", categoria: "Categ ERP" },
    });
    expect(merged.name).toBe("Foo do ERP");
    expect(merged.regular_price).toBe("199.90");
    expect(merged.stock_quantity).toBe(5);
    expect(merged.stock_status).toBe("instock");
    expect(merged.brand).toBe("Brand-ERP");
    expect(merged.images).toHaveLength(2);
    expect(merged.categories).toContain("Cat A");
    expect(merged.categories).toContain("Categ ERP");
  });

  it("falls back to scraper when ERP data is missing", () => {
    const merged = mergeScrapeAndErp({ sku: "SKU2", scrape: baseScrape, erp: null });
    expect(merged.name).toBe("Foo do Scraper");
    expect(merged.brand).toBe("Brand-Scrape");
    expect(merged.regular_price).toBe(null);
    expect(merged.stock_status).toBe("instock");
  });

  it("preserves both sources under extra", () => {
    const erp = { nome: "x" };
    const merged = mergeScrapeAndErp({ sku: "SKU3", scrape: baseScrape, erp });
    expect(merged.extra.scrape).toBe(baseScrape);
    expect(merged.extra.erp).toBe(erp);
  });

  it("marks out-of-stock when ERP reports zero", () => {
    const merged = mergeScrapeAndErp({
      sku: "SKU4",
      scrape: { ...baseScrape, stock_status: "in_stock" },
      erp: { estoque: 0 },
    });
    expect(merged.stock_status).toBe("outofstock");
  });

  it("propagates simple-product type and uses scraped price when ERP omits it", () => {
    const scrape: any = {
      ...baseScrape,
      type: "simple",
      price: "318.33",
      stock_qty: 3,
      installments: "ou 3x de R$ 106,11 sem juros",
      description_html: "<p><strong>Indicação:</strong></p>",
      video_urls: ["https://www.youtube.com/embed/Iv5DYlB33yQ"],
      pdf_urls: [],
      variations: [],
    };
    const merged = mergeScrapeAndErp({ sku: "SIMPLE-1", scrape, erp: null });
    expect(merged.type).toBe("simple");
    expect(merged.regular_price).toBe("318.33");
    expect(merged.stock_quantity).toBe(3);
    expect(merged.stock_status).toBe("instock");
    expect(merged.description).toContain("Indicação");
    expect(merged.video_urls).toContain("https://www.youtube.com/embed/Iv5DYlB33yQ");
    // installments + video should appear in meta for Woo
    const installmentMeta = merged.meta.find((m) => m.key === "_odontojf_installments");
    expect(installmentMeta?.value).toContain("3x");
    const videoMeta = merged.meta.find((m) => m.key === "_odontojf_video_url");
    expect(videoMeta).toBeDefined();
  });

  it("propagates variable-product type, derives min price and aggregate stock", () => {
    const scrape: any = {
      ...baseScrape,
      type: "variable",
      price: null,
      stock_qty: null,
      variations: [
        { name: "A1", sku: "20194", price: "105.60", price_text: "R$ 105,60", stock_status: "in_stock", stock_qty: 11 },
        { name: "A2", sku: "20195", price: "105.60", price_text: "R$ 105,60", stock_status: "in_stock", stock_qty: 22 },
        { name: "DB1", sku: "20198", price: null, price_text: null, stock_status: "out_of_stock", stock_qty: 0 },
      ],
      pdf_urls: [{ label: "Manual", url: "https://example.com/manual.pdf" }],
      video_urls: [],
    };
    const merged = mergeScrapeAndErp({ sku: "VAR-1", scrape, erp: null });
    expect(merged.type).toBe("variable");
    expect(merged.variations).toHaveLength(3);
    expect(merged.regular_price).toBe("105.60"); // min in-stock variation price
    expect(merged.stock_quantity).toBe(33); // 11 + 22 (DB1 is out)
    expect(merged.stock_status).toBe("instock");
    expect(merged.pdf_urls).toHaveLength(1);
    expect(merged.pdf_urls[0].label).toBe("Manual");
    const pdfMeta = merged.meta.find((m) => m.key === "_odontojf_pdf_url");
    expect(pdfMeta?.value).toBe("https://example.com/manual.pdf");
  });
});
