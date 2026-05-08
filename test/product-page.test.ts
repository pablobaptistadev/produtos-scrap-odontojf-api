import { describe, it, expect } from "vitest";
import { readFileSync } from "node:fs";
import { join } from "node:path";
import { parseProductHtml } from "../src/scraper/product-page";

const fixturesDir = join(__dirname, "fixtures");
function loadFixture(name: string): string {
  return readFileSync(join(fixturesDir, name), "utf-8");
}

describe("parseProductHtml — generic / fallback DOM cases", () => {
  it("falls back to DOM heuristics when __NEXT_DATA__ is absent", () => {
    const html = `
      <html><head>
        <title>Foo</title>
        <meta property="og:title" content="Foo" />
        <meta property="og:description" content="bar" />
        <meta property="og:image" content="https://cdn.example/img1.png" />
        <meta property="product:brand" content="ACME" />
      </head><body><p>Produto esgotado</p></body></html>
    `;
    const result = parseProductHtml("https://shop/foo", html);
    expect(result.title).toBe("Foo");
    expect(result.brand).toBe("ACME");
    expect(result.images[0]?.src).toBe("https://cdn.example/img1.png");
    expect(result.stock_status).toBe("out_of_stock");
    expect(result.type).toBe("simple");
    expect(result.id).toBeNull();
    expect(result.api_enriched).toBe(false);
  });
});

describe("parseProductHtml — simple product (Dispensador Pistola Universal)", () => {
  const html = loadFixture("dispensador-simple.html");
  const url = "https://dentalodontocirurgicajf.com.br/dispensador-pistola-universal-yller";
  const result = parseProductHtml(url, html);

  it("identifies the product as simple", () => {
    expect(result.type).toBe("simple");
    expect(result.variations).toEqual([]);
  });

  it("extracts internal id, title and brand from __NEXT_DATA__", () => {
    expect(result.id).toBe("Bico64qhnV5r5HfprQZf");
    expect(result.title).toBe("Dispensador Pistola Universal");
    expect(result.brand).toBe("YLLER");
  });

  it("extracts the short description (legend)", () => {
    expect(result.short_description).toBe("Embalagem com 1 unidade. Para cartuchos 1:1.");
  });

  it("extracts barcode (EAN)", () => {
    expect(result.barcode).toBe("7898952555593");
  });

  it("resolves category names from id lookup", () => {
    expect(result.category).toContain("Pistola Dispensadora");
  });

  it("returns description HTML and decoded plain text", () => {
    expect(result.description_html).toContain("Indicação");
    expect(result.description).toContain("Indicação");
    // entities decoded:
    expect(result.description).not.toContain("&ccedil;");
  });

  it("extracts gallery images at the largest available size", () => {
    expect(result.images.length).toBeGreaterThan(0);
    expect(result.images[0]!.src).toMatch(/_1600x1600\.png$|_800x800\.png$|_600x600\.png$/);
  });

  it("collects YouTube video URLs (from iframe in description)", () => {
    expect(result.video_urls.length).toBeGreaterThan(0);
    expect(result.video_urls[0]).toContain("Iv5DYlB33yQ");
  });

  it("filters logo / payment seal images out of description_images", () => {
    for (const src of result.description_images) {
      expect(src).not.toMatch(/\/payment\//);
      expect(src).not.toMatch(/\/selos\//);
    }
  });

  it("does not have detected_sku before API enrichment (parser only)", () => {
    // detected_sku and price are populated by enrichWithSpecificData
    expect(result.detected_sku).toBeNull();
    expect(result.price).toBeNull();
    expect(result.stock_qty).toBeNull();
    expect(result.api_enriched).toBe(false);
  });

  it("extracts shipping dimensions and provider_code from initialData", () => {
    expect(result.dimensions.weight).toBe(0.25);
    expect(result.dimensions.length).toBe(20);
    expect(result.dimensions.width).toBe(6);
    expect(result.dimensions.height).toBe(10);
    // Dispensador's parent providerCode is empty, so we expect null (not "")
    expect(result.provider_code).toBeNull();
  });
});

describe("parseProductHtml — variable product (Resina Elora APS - 4g)", () => {
  const html = loadFixture("resina-elora-variable.html");
  const url = "https://dentalodontocirurgicajf.com.br/resina-elora-aps-4g-fgm";
  const result = parseProductHtml(url, html);

  it("identifies the product as variable (NEXT_DATA type === 'family')", () => {
    expect(result.type).toBe("variable");
    expect(result.variations.length).toBeGreaterThanOrEqual(4);
  });

  it("extracts internal id and title", () => {
    expect(result.id).toBe("hcNfridkGu4aaMdh1nGF");
    expect(result.title).toBe("Resina Elora APS - 4g");
  });

  it("populates each variation with id, label, barcode, providerCode, dimensions; leaving sku/price for the API", () => {
    const a1 = result.variations.find((v) => v.name === "A1");
    expect(a1).toBeDefined();
    expect(a1!.id).toBe("AqP97k2YWhqEoAn9J419");
    expect(a1!.barcode).toBe("7899633834921");
    expect(a1!.provider_code).toBe("4000056236");
    // dimensions inherit from parent when variation overrides match it
    expect(a1!.dimensions.weight).toBe(0.05);
    expect(a1!.dimensions.length).toBe(10);
    expect(a1!.dimensions.width).toBe(5);
    expect(a1!.dimensions.height).toBe(3);
    // sku and price come from /api/product-specific-data; parser leaves them null
    expect(a1!.sku).toBeNull();
    expect(a1!.price).toBeNull();
    // each variation carries its own gallery (largest image picked)
    expect(a1!.images.length).toBeGreaterThan(0);
    expect(a1!.images[0].src).toMatch(/resina-elora-aps-a1-fgm.*_(800x800|1600x1600|600x600)\.png$/);
  });

  it("preserves variation names with special characters (DB-A3,5)", () => {
    const dba35 = result.variations.find((v) => v.name === "DB-A3,5");
    expect(dba35).toBeDefined();
  });

  it("extracts the manual PDF link from initialData.files", () => {
    expect(result.pdf_urls.length).toBeGreaterThan(0);
    expect(result.pdf_urls[0].url).toMatch(/resina-elora-aps-4g-fgm.*\.pdf$/);
    expect(result.pdf_urls[0].label.toLowerCase()).toContain("manual");
  });

  it("extracts a description with decoded Portuguese entities", () => {
    expect(result.description).toContain("Resina Elora APS");
    expect(result.description).not.toContain("&eacute;");
  });

  it("does not populate simple-product fields on a variable product", () => {
    expect(result.price).toBeNull();
    expect(result.price_text).toBeNull();
    expect(result.stock_qty).toBeNull();
  });
});
