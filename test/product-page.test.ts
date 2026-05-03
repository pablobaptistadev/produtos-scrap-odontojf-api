import { describe, it, expect } from "vitest";
import { parseProductHtml } from "../src/scraper/product-page";

describe("parseProductHtml", () => {
  it("extracts title, brand, images and description from a basic page", () => {
    const html = `
      <html><head>
        <title>Dispensador Pistola Universal</title>
        <meta property="og:title" content="Dispensador Pistola Universal Yller" />
        <meta property="og:description" content="Pistola dispensadora compatível com cartuchos de impressão." />
        <meta property="og:image" content="https://cdn.example/img1.png" />
        <meta property="product:brand" content="YLLER" />
        <meta property="product:availability" content="out of stock" />
      </head>
      <body>
        <img src="https://cdn.example/photo1.jpg" />
        <img src="https://cdn.example/photo2.jpg" />
        <input type="hidden" name="sku" value="DISP-001" />
        <p>Produto esgotado</p>
      </body></html>
    `;
    const result = parseProductHtml("https://shop/dispensador-pistola-universal-yller", html);
    expect(result.title).toContain("Dispensador Pistola Universal Yller");
    expect(result.brand).toBe("YLLER");
    expect(result.images.length).toBeGreaterThanOrEqual(2);
    expect(result.detected_sku).toBe("DISP-001");
    expect(result.slug).toBe("dispensador-pistola-universal-yller");
  });

  it("parses JSON-LD product data when present", () => {
    const html = `
      <html><head>
        <script type="application/ld+json">{"@type":"Product","name":"Foo","sku":"SKU-LD-01","brand":{"name":"Acme"},"category":"Categ > Sub"}</script>
      </head><body></body></html>
    `;
    const result = parseProductHtml("https://shop/foo", html);
    expect(result.detected_sku).toBe("SKU-LD-01");
    expect(result.brand).toBe("Acme");
    expect(result.category).toEqual(["Categ", "Sub"]);
  });

  it("detects out-of-stock from page text when meta absent", () => {
    const html = "<html><body><h1>Foo</h1><p>Produto esgotado</p></body></html>";
    const result = parseProductHtml("https://shop/foo", html);
    expect(result.stock_status).toBe("out_of_stock");
  });
});
