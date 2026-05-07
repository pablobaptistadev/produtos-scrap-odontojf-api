import type { ScrapeResult, ScrapeVariation } from "../scraper/product-page";

/**
 * Merge the scraped product data with the ERP product data into a single
 * payload suitable for posting to WooCommerce.
 *
 * Rules:
 *  - SKU is the primary key. ERP wins for SKU/price/stock; scraper wins for
 *    images/marketing copy.
 *  - Categories: combine, dedupe.
 *  - Images: prefer scraper (better quality / catalog photos).
 *  - Description: prefer scraped rich `description_html` (full marketing copy),
 *    fall back to ERP `descricao_completa`, then plain scraped description.
 *  - Type: scraper-detected `simple` vs `variable` is propagated to Woo.
 *  - For variable products, individual variation rows are surfaced under
 *    `variations` so the push-stage can later create them via the Woo
 *    `/products/{id}/variations` endpoint (not yet wired).
 *  - Any field present only on one side is preserved on the merged record under
 *    `extra.scrape` / `extra.erp` so nothing is lost.
 */

export interface MergedVariation {
  name: string;
  /** human-readable code (Código do produto). Null until enriched by the
   *  product-specific-data API call. */
  sku: string | null;
  /** manufacturer / supplier reference */
  provider_code: string | null;
  barcode: string | null;
  regular_price: string | null;
  stock_quantity: number | null;
  stock_status: "instock" | "outofstock" | null;
  /** weight in kg (Woo native field) */
  weight: string | null;
  /** Woo-shaped object: { length, width, height } in cm, all strings */
  dimensions: { length: string; width: string; height: string };
}

export interface MergedProduct {
  sku: string;
  type: "simple" | "variable";
  name: string;
  slug: string | null;
  description: string | null;
  short_description: string | null;
  brand: string | null;
  categories: string[];
  images: { src: string }[];
  regular_price: string | null;
  stock_quantity: number | null;
  stock_status: "instock" | "outofstock" | null;
  /** Woo native: weight in kg as a string (e.g. "0.25") */
  weight: string | null;
  /** Woo native: { length, width, height } in cm, all strings */
  dimensions: { length: string; width: string; height: string };
  /** EAN / GTIN of the parent product */
  barcode: string | null;
  /** manufacturer / supplier reference */
  provider_code: string | null;
  meta: Array<{ key: string; value: string }>;
  variations: MergedVariation[];
  video_urls: string[];
  pdf_urls: Array<{ label: string; url: string }>;
  extra: {
    scrape: unknown;
    erp: unknown;
  };
  source_url: string | null;
}

function asString(v: unknown): string | null {
  if (v == null) return null;
  if (typeof v === "string") return v.trim() || null;
  if (typeof v === "number") return String(v);
  return null;
}

function asNumber(v: unknown): number | null {
  if (v == null) return null;
  if (typeof v === "number" && Number.isFinite(v)) return v;
  if (typeof v === "string") {
    const parsed = Number(v.replace(",", "."));
    return Number.isFinite(parsed) ? parsed : null;
  }
  return null;
}

function pickFromErp(erp: any, candidates: string[]): unknown {
  if (!erp || typeof erp !== "object") return null;
  for (const key of candidates) {
    if (key in erp && erp[key] != null && erp[key] !== "") return erp[key];
  }
  // also try nested under `produto`
  const nested = (erp as any).produto ?? (erp as any).Produto ?? (erp as any).data;
  if (nested && typeof nested === "object") {
    for (const key of candidates) {
      if (key in nested && nested[key] != null && nested[key] !== "") return nested[key];
    }
  }
  return null;
}

export function mergeScrapeAndErp(input: {
  sku: string;
  scrape: ScrapeResult | null;
  erp: unknown | null;
}): MergedProduct {
  const { sku, scrape, erp } = input;

  const erpName = asString(pickFromErp(erp, ["nome", "descricao", "Descricao", "name", "title"]));
  const erpDescription = asString(pickFromErp(erp, ["descricao_completa", "descricao_longa", "DescricaoCompleta", "long_description", "descricao_detalhada"]));
  const erpShortDescription = asString(pickFromErp(erp, ["descricao_curta", "DescricaoCurta", "short_description"]));
  const erpPrice = asString(pickFromErp(erp, ["preco", "valor", "preco_venda", "PrecoVenda", "price"]));
  const erpStockQty = asNumber(pickFromErp(erp, ["estoque", "quantidade", "saldo", "Estoque", "stock_quantity"]));
  const erpBrand = asString(pickFromErp(erp, ["marca", "Marca", "brand"]));
  const erpCategory = pickFromErp(erp, ["categoria", "categorias", "Categoria", "Categorias"]);

  const categories = new Set<string>();
  for (const c of scrape?.category ?? []) categories.add(c);
  if (typeof erpCategory === "string") {
    erpCategory.split(/\s*[>/]\s*/).forEach((c) => c && categories.add(c));
  } else if (Array.isArray(erpCategory)) {
    erpCategory.forEach((c) => typeof c === "string" && categories.add(c));
  }

  // Effective stock derives from ERP first, then scraped variation aggregate
  // (sum of in-stock variation quantities), then simple-product stock_qty,
  // then meta-tag stock_status.
  const variationStockTotal = (scrape?.variations ?? []).reduce(
    (acc, v) => acc + (v.stock_status === "out_of_stock" ? 0 : v.stock_qty ?? 0),
    0,
  );
  const scrapeStockQty =
    scrape?.type === "variable"
      ? variationStockTotal || null
      : scrape?.stock_qty ?? null;

  const stockQuantity = erpStockQty ?? scrapeStockQty;

  const stockStatus: "instock" | "outofstock" | null = (() => {
    if (stockQuantity != null) return stockQuantity > 0 ? "instock" : "outofstock";
    if (scrape?.stock_status === "in_stock") return "instock";
    if (scrape?.stock_status === "out_of_stock") return "outofstock";
    return null;
  })();

  const images = (scrape?.images ?? []).map((img) => {
    // ScrapeImage is { src, alt } in the new schema; older legacy entries may be plain strings.
    if (typeof img === "string") return { src: img };
    return { src: img.src };
  });

  // Description: prefer rich scraped HTML (marketing copy + embeds), then ERP, then plain meta.
  const description =
    scrape?.description_html
    ?? erpDescription
    ?? scrape?.description
    ?? null;

  // Short description: ERP wins, then scraper subtitle.
  const shortDescription = erpShortDescription ?? scrape?.short_description ?? null;

  // Price: ERP wins, then simple-product price, then min variation price.
  const minVariationPrice =
    scrape?.type === "variable"
      ? minPriceFromVariations(scrape?.variations ?? [])
      : null;
  const regularPrice = erpPrice ?? scrape?.price ?? minVariationPrice;

  const meta: Array<{ key: string; value: string }> = [
    { key: "_odontojf_sku", value: sku },
  ];
  if (scrape?.url) meta.push({ key: "_odontojf_source_url", value: scrape.url });
  if (scrape?.detected_sku && scrape.detected_sku !== sku) {
    meta.push({ key: "_odontojf_detected_sku", value: scrape.detected_sku });
  }
  if (scrape?.installments) {
    meta.push({ key: "_odontojf_installments", value: scrape.installments });
  }
  for (const v of scrape?.video_urls ?? []) {
    meta.push({ key: "_odontojf_video_url", value: v });
  }
  for (const p of scrape?.pdf_urls ?? []) {
    meta.push({ key: "_odontojf_pdf_url", value: p.url });
  }

  const variations: MergedVariation[] = (scrape?.variations ?? []).map((v) => ({
    name: v.name,
    sku: v.sku,
    provider_code: v.provider_code ?? null,
    barcode: v.barcode ?? null,
    regular_price: v.price,
    stock_quantity: v.stock_qty,
    stock_status:
      v.stock_status === "out_of_stock"
        ? "outofstock"
        : v.stock_status === "in_stock"
        ? "instock"
        : null,
    weight: dimToStr(v.dimensions?.weight),
    dimensions: {
      length: dimToStr(v.dimensions?.length) ?? "",
      width: dimToStr(v.dimensions?.width) ?? "",
      height: dimToStr(v.dimensions?.height) ?? "",
    },
  }));

  // Parent shipping fields. Prefer ERP, fall back to scraped parent dimensions.
  const erpWeight = asNumber(pickFromErp(erp, ["peso", "Peso", "weight", "peso_kg"]));
  const erpLength = asNumber(pickFromErp(erp, ["comprimento", "length", "Comprimento", "comprimento_cm"]));
  const erpWidth = asNumber(pickFromErp(erp, ["largura", "width", "Largura", "largura_cm"]));
  const erpHeight = asNumber(pickFromErp(erp, ["altura", "height", "Altura", "altura_cm"]));

  const parentDims = scrape?.dimensions ?? { weight: null, length: null, width: null, height: null };
  const weight = dimToStr(erpWeight ?? parentDims.weight);
  const dimensions = {
    length: dimToStr(erpLength ?? parentDims.length) ?? "",
    width: dimToStr(erpWidth ?? parentDims.width) ?? "",
    height: dimToStr(erpHeight ?? parentDims.height) ?? "",
  };

  // ERP barcode/EAN — falls back to the scraped parent barcode.
  const erpBarcode = asString(pickFromErp(erp, ["codigo_barras", "ean", "gtin", "codigoBarras", "barcode"]));
  const barcode = erpBarcode ?? scrape?.barcode ?? null;
  // ERP supplier/manufacturer code — falls back to the scraped parent providerCode.
  const erpProvider = asString(pickFromErp(erp, ["codigo_fornecedor", "providerCode", "provider_code", "codigo_fabricante"]));
  const provider_code = erpProvider ?? scrape?.provider_code ?? null;

  if (barcode) meta.push({ key: "_odontojf_barcode", value: barcode });
  if (provider_code) meta.push({ key: "_odontojf_provider_code", value: provider_code });

  return {
    sku,
    type: scrape?.type ?? "simple",
    name: erpName ?? scrape?.title ?? sku,
    slug: scrape?.slug ?? null,
    description,
    short_description: shortDescription,
    brand: erpBrand ?? scrape?.brand ?? null,
    categories: Array.from(categories),
    images,
    regular_price: regularPrice,
    stock_quantity: stockQuantity,
    stock_status: stockStatus,
    weight,
    dimensions,
    barcode,
    provider_code,
    meta,
    variations,
    video_urls: scrape?.video_urls ?? [],
    pdf_urls: scrape?.pdf_urls ?? [],
    extra: { scrape: scrape ?? null, erp: erp ?? null },
    source_url: scrape?.url ?? null,
  };
}

function dimToStr(value: number | null | undefined): string | null {
  if (value == null) return null;
  // strip insignificant trailing zeros — Woo accepts strings.
  return String(value);
}

function minPriceFromVariations(variations: ScrapeVariation[]): string | null {
  let min: number | null = null;
  for (const v of variations) {
    if (!v.price || v.stock_status === "out_of_stock") continue;
    const n = Number.parseFloat(v.price);
    if (!Number.isFinite(n)) continue;
    if (min == null || n < min) min = n;
  }
  return min == null ? null : min.toFixed(2);
}
