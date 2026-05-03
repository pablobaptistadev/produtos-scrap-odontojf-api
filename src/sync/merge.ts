import type { ScrapeResult } from "../scraper/product-page";

/**
 * Merge the scraped product data with the ERP product data into a single
 * payload suitable for posting to WooCommerce.
 *
 * Rules:
 *  - SKU is the primary key. ERP wins for SKU/price/stock; scraper wins for
 *    images/marketing copy.
 *  - Categories: combine, dedupe.
 *  - Images: prefer scraper (better quality / catalog photos).
 *  - Description: prefer ERP `descricao_completa` if available, otherwise scraped.
 *  - Any field present only on one side is preserved on the merged record under
 *    `extra.scrape` / `extra.erp` so nothing is lost.
 */

export interface MergedProduct {
  sku: string;
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
  meta: Array<{ key: string; value: string }>;
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

  const stockStatus: "instock" | "outofstock" | null = (() => {
    if (erpStockQty != null) return erpStockQty > 0 ? "instock" : "outofstock";
    if (scrape?.stock_status === "in_stock") return "instock";
    if (scrape?.stock_status === "out_of_stock") return "outofstock";
    return null;
  })();

  const images = (scrape?.images ?? []).map((src) => ({ src }));

  const meta: Array<{ key: string; value: string }> = [
    { key: "_odontojf_sku", value: sku },
  ];
  if (scrape?.url) meta.push({ key: "_odontojf_source_url", value: scrape.url });
  if (scrape?.detected_sku && scrape.detected_sku !== sku) {
    meta.push({ key: "_odontojf_detected_sku", value: scrape.detected_sku });
  }

  return {
    sku,
    name: erpName ?? scrape?.title ?? sku,
    slug: scrape?.slug ?? null,
    description: erpDescription ?? scrape?.description ?? null,
    short_description: erpShortDescription,
    brand: erpBrand ?? scrape?.brand ?? null,
    categories: Array.from(categories),
    images,
    regular_price: erpPrice,
    stock_quantity: erpStockQty,
    stock_status: stockStatus,
    meta,
    extra: { scrape: scrape ?? null, erp: erp ?? null },
    source_url: scrape?.url ?? null,
  };
}
