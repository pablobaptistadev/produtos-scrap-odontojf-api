import type { ScrapeImage, ScrapeResult, ScrapeVariation } from "../scraper/product-page";

/**
 * Merge the scraped product data with the ERP product data into a single
 * payload shaped for the WooCommerce REST API.
 *
 * Identifier rules (loja → ERP → Woo all share the same SKU):
 *   - sku           = scrape.detected_sku (= initialData internalId, "Código do produto")
 *   - this MUST equal the ERP `codigo` field. We carry both the resolved sku
 *     and a sku_check note in meta so divergences are detectable downstream.
 *
 * Source-of-truth precedence (when both sides exist):
 *   - name / description     → ERP wins (ERP has the cleaned `descricao` /
 *                              `descricaoComplementar`); scraper as fallback.
 *   - description_html       → SCRAPER wins (rich marketing copy with embedded
 *                              videos and entities decoded).
 *   - short_description      → ERP `descricaoComplementar` → scraper `legend`.
 *   - regular_price / stock  → ERP `preco` / `estoque` → scraper.
 *   - sale_price             → ERP `precoPromocional` (>0). Loja não expõe.
 *   - weight                 → ERP `pesoBruto` (Woo uses gross for shipping)
 *                              → scraper `dimensions.weight`.
 *   - dimensions             → ERP `altura/largura/comprimento` → scraper.
 *   - barcode                → ERP `codigosBarras[].codigoBarra` (active=true)
 *                              → scraper `barcode`.
 *   - provider_code          → ERP `fornecedorReferenciaCodigo` → scraper.
 *   - brand                  → ERP `marca` → scraper. Sent both as a Woo
 *                              attribute "Marca" and as meta `_odontojf_brand`.
 *   - categories             → ERP hierarchy (categoria > subCategoria > grupo
 *                              > subGrupo) when available; scraper as fallback.
 *   - attributes             → ERP `atributos[]` (custom attribute=value),
 *                              "Marca", and (variable) the variation axis.
 *   - variations             → built from scraper.variations (loja é a fonte
 *                              canônica para o set de variações). Each entry
 *                              is shaped for `POST /products/{id}/variations`.
 *   - images                 → scraper (gallery + per-variation; high-res CDN).
 *   - video_urls / pdf_urls  → scraper (loja-only).
 *
 * Extras saved as Woo meta_data (every key prefixed `_odontojf_…`):
 *   - _odontojf_sku, _odontojf_source_url
 *   - _odontojf_brand, _odontojf_provider_code, _odontojf_barcode
 *   - _odontojf_peso_liquido (kg)
 *   - _odontojf_dimensoes (JSON: { peso_bruto, peso_liquido, altura,
 *                                  largura, comprimento })
 *   - _odontojf_video_url (one entry per video)
 *   - _odontojf_pdf_url   (one entry per pdf)
 *   - _odontojf_installments (e.g. "ou 3x de R$ 106,11 sem juros")
 *
 * The full untouched scrape + erp objects are still preserved under `extra`,
 * so anything not surfaced explicitly is still available downstream.
 */

export interface MergedAttribute {
  /** Woo attribute name. Free-text, taxonomy resolution happens server-side. */
  name: string;
  /** Display options (always an array, even for single-value attributes). */
  options: string[];
  /** Whether this attribute drives variations (true only for the variation axis). */
  variation: boolean;
  /** Whether the attribute is shown on the product page. */
  visible: boolean;
  position?: number;
}

export interface MergedVariation {
  /** Internal site id (used to fetch `/api/product-specific-data`). Kept for
   *  traceability and as the deterministic key when mirroring to R2. */
  scrape_id: string;
  /** Human-readable code (`internalId`) — same as ERP `codigo`. */
  sku: string | null;
  /** Variation label as shown on the storefront (e.g. "A1", "DB-A3,5"). */
  name: string;
  provider_code: string | null;
  barcode: string | null;
  regular_price: string | null;
  manage_stock: boolean;
  stock_quantity: number | null;
  stock_status: "instock" | "outofstock" | null;
  /** Woo native: weight (kg) — uses pesoBruto when available, scraper as fallback. */
  weight: string | null;
  /** Woo native: { length, width, height } in cm, all strings. */
  dimensions: { length: string; width: string; height: string };
  /** Single image for the variation. Empty `src` means "use parent image". */
  image: { src: string } | null;
  /** Variation attributes — only the variation axis (e.g. {Variação: "A1"}). */
  attributes: Array<{ name: string; option: string }>;
  meta_data: Array<{ key: string; value: string }>;
}

export interface MergedProduct {
  sku: string;
  type: "simple" | "variable";
  name: string;
  slug: string | null;
  status: "publish" | "draft" | null;
  description: string | null;
  short_description: string | null;
  brand: string | null;
  /** Woo categories — ordered from broader to narrower when ERP exposes a hierarchy. */
  categories: Array<{ name: string }>;
  images: Array<{ src: string }>;
  regular_price: string | null;
  sale_price: string | null;
  manage_stock: boolean;
  stock_quantity: number | null;
  stock_status: "instock" | "outofstock" | null;
  weight: string | null;
  dimensions: { length: string; width: string; height: string };
  barcode: string | null;
  provider_code: string | null;
  attributes: MergedAttribute[];
  /** Variations are NOT a Woo /products field — Woo expects them via
   *  `POST /products/{id}/variations`. Carried here so the push stage can
   *  iterate after the parent is created. */
  variations: MergedVariation[];
  meta_data: Array<{ key: string; value: string }>;
  video_urls: string[];
  pdf_urls: Array<{ label: string; url: string }>;
  extra: { scrape: unknown; erp: unknown };
  source_url: string | null;
  /** Diagnostics for this merge run. */
  warnings: string[];
}

// ---------- helpers ----------

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
  // Space ERP wraps the actual product under `produtos[0]`.
  const list = (erp as any).produtos;
  if (Array.isArray(list) && list.length > 0) {
    const first = list[0];
    if (first && typeof first === "object") {
      for (const key of candidates) {
        if (key in first && first[key] != null && first[key] !== "") return first[key];
      }
    }
  }
  // Some integrations expose the produto under .produto / .Produto / .data
  for (const wrap of ["produto", "Produto", "data"]) {
    const nested = (erp as any)[wrap];
    if (nested && typeof nested === "object") {
      for (const key of candidates) {
        if (key in nested && nested[key] != null && nested[key] !== "") return nested[key];
      }
    }
  }
  return null;
}

function dimToStr(value: number | null | undefined): string | null {
  if (value == null) return null;
  return String(value);
}

function nonEmpty(value: string | null | undefined): string | null {
  if (value == null) return null;
  const trimmed = String(value).trim();
  return trimmed.length > 0 ? trimmed : null;
}

function pickFirstImage(images: ScrapeImage[] | undefined, fallback: ScrapeImage[] | undefined): { src: string } | null {
  const src = images?.[0]?.src ?? fallback?.[0]?.src ?? null;
  return src ? { src } : null;
}

function buildErpCategoryHierarchy(erp: any): Array<{ name: string }> | null {
  const names: string[] = [];
  // Order: linha → grupoWeb → grupo → subGrupo → categoria → subCategoria.
  // The first 4 are taxonomic context; the last 2 are the leaf categorisation.
  // We push from broad to narrow so Woo can mirror the breadcrumb structure.
  const fields = ["linha", "grupoWeb", "grupo", "subGrupo", "categoria", "subCategoria"];
  for (const f of fields) {
    const obj = pickFromErp(erp, [f]);
    if (obj && typeof obj === "object") {
      const desc = asString((obj as any).descricao ?? (obj as any).Descricao ?? (obj as any).nome);
      if (desc) names.push(desc);
    }
  }
  if (names.length === 0) return null;
  // dedupe consecutive duplicates while preserving order
  const out: string[] = [];
  for (const n of names) {
    if (out[out.length - 1] !== n) out.push(n);
  }
  return out.map((n) => ({ name: n }));
}

function buildErpAttributes(erp: any): MergedAttribute[] {
  const list = pickFromErp(erp, ["atributos"]);
  if (!Array.isArray(list)) return [];
  const out: MergedAttribute[] = [];
  for (const item of list) {
    if (!item || typeof item !== "object") continue;
    const name = asString((item as any).atributo ?? (item as any).nome);
    const value = asString((item as any).valorAtributo ?? (item as any).valor);
    if (!name || !value) continue;
    out.push({ name, options: [value], variation: false, visible: true });
  }
  return out;
}

function activeBarcodeFromErp(erp: any): string | null {
  const list = pickFromErp(erp, ["codigosBarras"]);
  if (!Array.isArray(list)) return null;
  // Prefer entries flagged active=true, then the first non-empty one.
  const active = list.find((b: any) => b?.ativo === true && asString(b?.codigoBarra));
  const any = active ?? list.find((b: any) => asString(b?.codigoBarra));
  return any ? asString(any.codigoBarra) : null;
}

// ---------- merge ----------

export function mergeScrapeAndErp(input: {
  sku: string;
  scrape: ScrapeResult | null;
  erp: unknown | null;
}): MergedProduct {
  const { sku, scrape, erp } = input;
  const warnings: string[] = [];

  // ---------- identifiers ----------
  // sku must match across loja / ERP / Woo. Detect divergence and warn.
  const erpCodigo = asString(pickFromErp(erp, ["codigo", "Codigo", "code"]));
  if (erpCodigo && erpCodigo !== sku) {
    warnings.push(
      `sku mismatch — loja=${sku} ERP=${erpCodigo}. Using loja value (Woo SKU).`,
    );
  }
  const erpAtivo = pickFromErp(erp, ["ativo", "Ativo"]);
  const status: "publish" | "draft" | null =
    erpAtivo === false ? "draft" : erpAtivo === true ? "publish" : null;

  // ---------- copy from ERP, fall back to scraper ----------
  const erpName = asString(pickFromErp(erp, ["descricao", "Descricao", "name", "title"]));
  const erpLongDescription = asString(
    pickFromErp(erp, ["descricaoDetalhada", "descricao_detalhada", "descricao_longa", "DescricaoCompleta", "long_description"]),
  );
  const erpShortDescription = asString(
    pickFromErp(erp, ["descricaoComplementar", "descricao_complementar", "descricao_curta", "short_description"]),
  );
  const erpPrice = asNumber(pickFromErp(erp, ["preco", "valor", "preco_venda", "PrecoVenda", "price"]));
  const erpSalePrice = asNumber(pickFromErp(erp, ["precoPromocional", "preco_promocional", "sale_price"]));
  const erpStockQty = asNumber(pickFromErp(erp, ["estoque", "quantidade", "saldo", "Estoque", "stock_quantity"]));
  const erpBrand = asString(pickFromErp(erp, ["marca", "Marca", "brand"]));
  const erpProvider = asString(
    pickFromErp(erp, ["fornecedorReferenciaCodigo", "codigo_fornecedor", "providerCode", "provider_code"]),
  );
  const erpBarcode = activeBarcodeFromErp(erp) ?? asString(pickFromErp(erp, ["codigo_barras", "ean", "gtin"]));
  const erpPesoBruto = asNumber(pickFromErp(erp, ["pesoBruto", "peso_bruto"]));
  const erpPesoLiquido = asNumber(pickFromErp(erp, ["pesoLiquido", "peso_liquido"]));
  const erpAltura = asNumber(pickFromErp(erp, ["altura", "Altura", "height"]));
  const erpLargura = asNumber(pickFromErp(erp, ["largura", "Largura", "width"]));
  const erpComprimento = asNumber(pickFromErp(erp, ["comprimento", "Comprimento", "length"]));

  // ---------- categories ----------
  const erpCategories = buildErpCategoryHierarchy(erp);
  const categories: Array<{ name: string }> =
    erpCategories
    ?? (scrape?.category ?? []).map((name) => ({ name }));

  // ---------- variations (built from scraper) ----------
  const scrapeVars = scrape?.variations ?? [];
  const isVariable = scrape?.type === "variable" && scrapeVars.length > 0;
  const variationAxisName = "Variação"; // generic; storefront doesn't label the axis
  const parentImages = scrape?.images ?? [];

  const variations: MergedVariation[] = scrapeVars.map((v) => {
    const dims = v.dimensions ?? { weight: null, length: null, width: null, height: null };
    const image = pickFirstImage(v.images, parentImages);
    const variationMeta: Array<{ key: string; value: string }> = [];
    if (v.barcode) variationMeta.push({ key: "_odontojf_barcode", value: v.barcode });
    if (v.provider_code) variationMeta.push({ key: "_odontojf_provider_code", value: v.provider_code });
    if (v.id) variationMeta.push({ key: "_odontojf_scrape_id", value: v.id });

    return {
      scrape_id: v.id,
      sku: v.sku,
      name: v.name,
      provider_code: v.provider_code ?? null,
      barcode: v.barcode ?? null,
      regular_price: v.price,
      manage_stock: v.stock_qty != null,
      stock_quantity: v.stock_qty,
      stock_status:
        v.stock_status === "out_of_stock"
          ? "outofstock"
          : v.stock_status === "in_stock"
          ? "instock"
          : null,
      weight: dimToStr(dims.weight),
      dimensions: {
        length: dimToStr(dims.length) ?? "",
        width: dimToStr(dims.width) ?? "",
        height: dimToStr(dims.height) ?? "",
      },
      image,
      attributes: [{ name: variationAxisName, option: v.name }],
      meta_data: variationMeta,
    };
  });

  // ---------- attributes (parent) ----------
  const attributes: MergedAttribute[] = [];
  // Brand → Woo attribute "Marca" so it picks up brand-aware themes / SEO.
  const brandFinal = erpBrand ?? scrape?.brand ?? null;
  if (brandFinal) {
    attributes.push({ name: "Marca", options: [brandFinal], variation: false, visible: true });
  }
  // ERP custom attributes (atributos[] → ex: "Peso: 350G", "Tipo: …")
  attributes.push(...buildErpAttributes(erp));
  // Variation axis (last so it shows after Marca/atributos)
  if (isVariable) {
    attributes.push({
      name: variationAxisName,
      options: scrapeVars.map((v) => v.name).filter(Boolean),
      variation: true,
      visible: true,
    });
  }

  // ---------- parent stock / price ----------
  // For variable products, parent regular_price is the min in-stock variation
  // price (Woo recomputes from variations anyway, but we send a sensible value).
  const minVariationPrice =
    isVariable
      ? minPriceFromVariations(scrapeVars)
      : null;
  const variationStockTotal = scrapeVars.reduce(
    (acc, v) => acc + (v.stock_status === "out_of_stock" ? 0 : v.stock_qty ?? 0),
    0,
  );
  const scrapeStockQty = isVariable ? (variationStockTotal || null) : (scrape?.stock_qty ?? null);

  const regularPriceNum = erpPrice ?? (scrape?.price != null ? Number(scrape.price) : null) ?? null;
  const regular_price = regularPriceNum != null ? regularPriceNum.toFixed(2)
    : minVariationPrice;
  const sale_price = erpSalePrice && erpSalePrice > 0 ? erpSalePrice.toFixed(2) : null;

  const stockQuantity = erpStockQty ?? scrapeStockQty;
  const stockStatus: "instock" | "outofstock" | null = (() => {
    if (stockQuantity != null) return stockQuantity > 0 ? "instock" : "outofstock";
    if (scrape?.stock_status === "in_stock") return "instock";
    if (scrape?.stock_status === "out_of_stock") return "outofstock";
    return null;
  })();

  // ---------- weight & dimensions ----------
  const parentDims = scrape?.dimensions ?? { weight: null, length: null, width: null, height: null };
  // Loja sends a single weight; ERP separates bruto/liquido. Woo `weight` uses bruto.
  const pesoBruto = erpPesoBruto ?? parentDims.weight;
  const pesoLiquido = erpPesoLiquido; // null when ERP is skipped
  const length = erpComprimento ?? parentDims.length;
  const width = erpLargura ?? parentDims.width;
  const height = erpAltura ?? parentDims.height;

  const weight = dimToStr(pesoBruto);
  const dimensions = {
    length: dimToStr(length) ?? "",
    width: dimToStr(width) ?? "",
    height: dimToStr(height) ?? "",
  };

  // ---------- description ----------
  // Rich HTML wins for the long body (videos, embedded images, formatting).
  // ERP description falls back when scraper has nothing.
  const description =
    scrape?.description_html
    ?? erpLongDescription
    ?? scrape?.description
    ?? null;
  const short_description = erpShortDescription ?? scrape?.short_description ?? null;

  // ---------- meta_data ----------
  const meta_data: Array<{ key: string; value: string }> = [];
  meta_data.push({ key: "_odontojf_sku", value: sku });
  if (scrape?.url) meta_data.push({ key: "_odontojf_source_url", value: scrape.url });
  if (scrape?.id) meta_data.push({ key: "_odontojf_scrape_id", value: scrape.id });
  if (brandFinal) meta_data.push({ key: "_odontojf_brand", value: brandFinal });
  const provider_code = erpProvider ?? scrape?.provider_code ?? null;
  if (provider_code) meta_data.push({ key: "_odontojf_provider_code", value: provider_code });
  const barcode = erpBarcode ?? scrape?.barcode ?? null;
  if (barcode) meta_data.push({ key: "_odontojf_barcode", value: barcode });
  if (scrape?.installments) meta_data.push({ key: "_odontojf_installments", value: scrape.installments });
  for (const v of scrape?.video_urls ?? []) meta_data.push({ key: "_odontojf_video_url", value: v });
  for (const p of scrape?.pdf_urls ?? []) meta_data.push({ key: "_odontojf_pdf_url", value: p.url });

  if (pesoLiquido != null) meta_data.push({ key: "_odontojf_peso_liquido", value: String(pesoLiquido) });
  // Consolidated dimensions JSON (everything in one place for theme/extension reads).
  const dimensoes_extra = {
    peso_bruto: pesoBruto,
    peso_liquido: pesoLiquido,
    altura: height,
    largura: width,
    comprimento: length,
  };
  meta_data.push({ key: "_odontojf_dimensoes", value: JSON.stringify(dimensoes_extra) });

  return {
    sku,
    type: isVariable ? "variable" : "simple",
    name: erpName ?? scrape?.title ?? sku,
    slug: scrape?.slug ?? null,
    status,
    description,
    short_description,
    brand: brandFinal,
    categories,
    images: parentImages.map((img) => ({ src: img.src })),
    regular_price,
    sale_price,
    manage_stock: stockQuantity != null,
    stock_quantity: stockQuantity,
    stock_status: stockStatus,
    weight,
    dimensions,
    barcode,
    provider_code,
    attributes,
    variations,
    meta_data,
    video_urls: scrape?.video_urls ?? [],
    pdf_urls: scrape?.pdf_urls ?? [],
    extra: { scrape: scrape ?? null, erp: erp ?? null },
    source_url: scrape?.url ?? null,
    warnings,
  };
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
