import { describe, it, expect } from "vitest";
import { mergeScrapeAndErp } from "../src/sync/merge";

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

describe("mergeScrapeAndErp — basics", () => {
  it("uses ERP name and price, scraper images", () => {
    const merged = mergeScrapeAndErp({
      sku: "SKU1",
      scrape: baseScrape,
      erp: { codigo: "SKU1", descricao: "Foo do ERP", preco: 199.9, estoque: 5, marca: "Brand-ERP" },
    });
    expect(merged.name).toBe("Foo do ERP");
    expect(merged.regular_price).toBe("199.90");
    expect(merged.stock_quantity).toBe(5);
    expect(merged.stock_status).toBe("instock");
    expect(merged.brand).toBe("Brand-ERP");
    expect(merged.images).toHaveLength(2);
    // Brand → Woo attribute "Marca"
    const brandAttr = merged.attributes.find((a) => a.name === "Marca");
    expect(brandAttr?.options).toEqual(["Brand-ERP"]);
    expect(brandAttr?.variation).toBe(false);
  });

  it("falls back to scraper when ERP data is missing", () => {
    const merged = mergeScrapeAndErp({ sku: "SKU2", scrape: baseScrape, erp: null });
    expect(merged.name).toBe("Foo do Scraper");
    expect(merged.brand).toBe("Brand-Scrape");
    expect(merged.regular_price).toBeNull();
    expect(merged.stock_status).toBe("instock");
    expect(merged.warnings).toEqual([]);
  });

  it("warns when ERP `codigo` differs from the loja sku", () => {
    const merged = mergeScrapeAndErp({
      sku: "20194",
      scrape: { ...baseScrape, type: "simple" },
      erp: { codigo: 99999, descricao: "x" },
    });
    expect(merged.warnings.some((w) => w.includes("sku mismatch"))).toBe(true);
    expect(merged.sku).toBe("20194"); // we keep the loja value as the Woo sku
  });

  it("preserves both sources under extra", () => {
    const erp = { descricao: "x", codigo: "SKU3" };
    const merged = mergeScrapeAndErp({ sku: "SKU3", scrape: baseScrape, erp });
    expect(merged.extra.scrape).toBe(baseScrape);
    expect(merged.extra.erp).toBe(erp);
  });
});

describe("mergeScrapeAndErp — weight & dimensions", () => {
  it("uses pesoBruto for Woo weight; pesoLiquido goes to meta", () => {
    const merged = mergeScrapeAndErp({
      sku: "SKU-W",
      scrape: {
        ...baseScrape,
        dimensions: { weight: 0.25, length: 20, width: 6, height: 10 },
      },
      erp: { codigo: "SKU-W", pesoBruto: 0.32, pesoLiquido: 0.25 },
    });
    expect(merged.weight).toBe("0.32"); // bruto wins
    expect(merged.dimensions).toEqual({ length: "20", width: "6", height: "10" });
    const liquido = merged.meta_data.find((m) => m.key === "_odontojf_peso_liquido");
    expect(liquido?.value).toBe("0.25");
    const dimsMeta = merged.meta_data.find((m) => m.key === "_odontojf_dimensoes");
    const parsed = JSON.parse(dimsMeta!.value);
    expect(parsed.peso_bruto).toBe(0.32);
    expect(parsed.peso_liquido).toBe(0.25);
    expect(parsed.altura).toBe(10);
  });

  it("falls back to scraper dimensions when ERP omits them", () => {
    const merged = mergeScrapeAndErp({
      sku: "SKU-W2",
      scrape: { ...baseScrape, dimensions: { weight: 0.25, length: 20, width: 6, height: 10 } },
      erp: null,
    });
    expect(merged.weight).toBe("0.25");
    expect(merged.dimensions).toEqual({ length: "20", width: "6", height: "10" });
    const dimsMeta = JSON.parse(merged.meta_data.find((m) => m.key === "_odontojf_dimensoes")!.value);
    expect(dimsMeta.peso_bruto).toBe(0.25);
    expect(dimsMeta.peso_liquido).toBeNull();
  });
});

describe("mergeScrapeAndErp — categories", () => {
  it("prefers ERP hierarchical categories over the scraped string", () => {
    const merged = mergeScrapeAndErp({
      sku: "SKU-C",
      scrape: { ...baseScrape, category: ["Resina"] },
      erp: {
        codigo: "SKU-C",
        linha: { codigo: 12, descricao: "Restauradores" },
        grupoWeb: { codigo: 1, descricao: "Resina Composta" },
        categoria: { codigo: 4, descricao: "Resina Estética" },
        subCategoria: { codigo: 1, descricao: "APS" },
      },
    });
    expect(merged.categories.map((c) => c.name)).toEqual([
      "Restauradores", "Resina Composta", "Resina Estética", "APS",
    ]);
  });

  it("falls back to scraped categories when ERP omits the hierarchy", () => {
    const merged = mergeScrapeAndErp({
      sku: "SKU-C2",
      scrape: { ...baseScrape, category: ["Pistola Dispensadora"] },
      erp: null,
    });
    expect(merged.categories).toEqual([{ name: "Pistola Dispensadora" }]);
  });
});

describe("mergeScrapeAndErp — variable products", () => {
  const variableScrape: any = {
    ...baseScrape,
    type: "variable",
    title: "Resina X",
    brand: "FGM",
    category: ["Resina"],
    images: [{ src: "https://cdn.example/parent.jpg", alt: null }],
    variations: [
      {
        id: "v1", sku: "20194", name: "A1",
        provider_code: "4000056236", barcode: "7899633834921",
        price: "105.60", price_text: "R$ 105,60",
        stock_status: "in_stock", stock_qty: 11,
        dimensions: { weight: 0.05, length: 10, width: 5, height: 3 },
        images: [{ src: "https://cdn.example/a1.jpg", alt: null }],
      },
      {
        id: "v2", sku: "20198", name: "DB1",
        provider_code: null, barcode: "7899633835096",
        price: "149.18", price_text: "R$ 149,18",
        stock_status: "out_of_stock", stock_qty: 0,
        dimensions: { weight: 0.05, length: 10, width: 5, height: 3 },
        images: [],
      },
    ],
  };

  it("emits Woo-shaped attributes including the 'Variação' axis", () => {
    const merged = mergeScrapeAndErp({ sku: "20194", scrape: variableScrape, erp: null });
    expect(merged.type).toBe("variable");
    const axis = merged.attributes.find((a) => a.name === "Variação");
    expect(axis?.variation).toBe(true);
    expect(axis?.options).toEqual(["A1", "DB1"]);
    const brand = merged.attributes.find((a) => a.name === "Marca");
    expect(brand?.options).toEqual(["FGM"]);
    expect(brand?.variation).toBe(false);
  });

  it("propagates ERP `atributos[]` as parent attributes (variation:false)", () => {
    const merged = mergeScrapeAndErp({
      sku: "20194",
      scrape: variableScrape,
      erp: {
        codigo: "20194",
        atributos: [
          { atributo: "Volume", valorAtributo: "4g" },
          { atributo: "Cor base", valorAtributo: "Universal" },
        ],
      },
    });
    const volume = merged.attributes.find((a) => a.name === "Volume");
    expect(volume?.options).toEqual(["4g"]);
    expect(volume?.variation).toBe(false);
  });

  it("builds Woo-shaped variations with sku, dimensions, image fallback and axis option", () => {
    const merged = mergeScrapeAndErp({ sku: "20194", scrape: variableScrape, erp: null });
    expect(merged.variations).toHaveLength(2);
    const a1 = merged.variations[0];
    expect(a1.sku).toBe("20194");
    expect(a1.regular_price).toBe("105.60");
    expect(a1.stock_quantity).toBe(11);
    expect(a1.stock_status).toBe("instock");
    expect(a1.weight).toBe("0.05");
    expect(a1.dimensions).toEqual({ length: "10", width: "5", height: "3" });
    expect(a1.image?.src).toBe("https://cdn.example/a1.jpg");
    expect(a1.attributes).toEqual([{ name: "Variação", option: "A1" }]);
    expect(a1.meta_data.find((m) => m.key === "_odontojf_barcode")?.value).toBe("7899633834921");

    // DB1 has no images → falls back to the parent image
    const db1 = merged.variations[1];
    expect(db1.image?.src).toBe("https://cdn.example/parent.jpg");
    expect(db1.stock_status).toBe("outofstock");
  });

  it("aggregates parent stock from in-stock variations and uses min in-stock price", () => {
    const merged = mergeScrapeAndErp({ sku: "20194", scrape: variableScrape, erp: null });
    expect(merged.stock_quantity).toBe(11); // DB1 is out (excluded)
    expect(merged.regular_price).toBe("105.60"); // min in-stock
  });
});

describe("mergeScrapeAndErp — barcode preference", () => {
  it("picks the active codigosBarras entry from ERP over the scraped barcode", () => {
    const merged = mergeScrapeAndErp({
      sku: "SKU-B",
      scrape: { ...baseScrape, barcode: "7898952555593" },
      erp: {
        codigo: "SKU-B",
        codigosBarras: [
          { ativo: false, codigoBarra: "0001" },
          { ativo: true, codigoBarra: "7891234567890" },
        ],
      },
    });
    expect(merged.barcode).toBe("7891234567890");
  });
});

describe("mergeScrapeAndErp — sale price", () => {
  it("uses ERP precoPromocional when > 0, otherwise null", () => {
    const merged = mergeScrapeAndErp({
      sku: "SKU-S",
      scrape: baseScrape,
      erp: { codigo: "SKU-S", preco: 100, precoPromocional: 79.9 },
    });
    expect(merged.sale_price).toBe("79.90");
  });

  it("does not surface zero promotional price", () => {
    const merged = mergeScrapeAndErp({
      sku: "SKU-S2",
      scrape: baseScrape,
      erp: { codigo: "SKU-S2", preco: 100, precoPromocional: 0 },
    });
    expect(merged.sale_price).toBeNull();
  });
});
