import { describe, it, expect } from "vitest";
import { mergeScrapeAndErp } from "../src/sync/merge";

describe("mergeScrapeAndErp", () => {
  const baseScrape: any = {
    url: "https://shop/foo",
    slug: "foo",
    title: "Foo do Scraper",
    brand: "Brand-Scrape",
    category: ["Cat A", "Cat B"],
    description: "desc do scraper",
    images: ["https://cdn.example/a.jpg", "https://cdn.example/b.jpg"],
    stock_status: "in_stock",
    raw_meta: {},
    detected_sku: null,
    fetched_at: "2026-05-02T00:00:00Z",
    status_code: 200,
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
});
