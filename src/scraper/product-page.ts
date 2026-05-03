import type { Env } from "../env";
import { fetchWithTimeout, parseIntEnv } from "../core";

export interface ScrapeResult {
  url: string;
  slug: string;
  title: string | null;
  brand: string | null;
  category: string[];
  description: string | null;
  images: string[];
  stock_status: string | null;
  raw_meta: Record<string, string>;
  detected_sku: string | null;
  fetched_at: string;
  status_code: number;
}

const META_RE = /<meta\s+([^>]+?)\/?>/gi;
const ATTR_RE = /(\w[\w:-]*)\s*=\s*"([^"]*)"/g;
const TITLE_RE = /<title[^>]*>([\s\S]*?)<\/title>/i;
const SCRIPT_LD_RE = /<script[^>]*type=["']application\/ld\+json["'][^>]*>([\s\S]*?)<\/script>/gi;
const SCRIPT_NEXT_DATA_RE = /<script[^>]*id=["']__NEXT_DATA__["'][^>]*>([\s\S]*?)<\/script>/i;
const IMG_RE = /<img\s+([^>]*?)\/?>/gi;
const HIDDEN_SKU_RE = /<input[^>]*name=["'](?:sku|codigo|product[_-]?code)["'][^>]*value=["']([^"']+)["']/i;
const DATA_SKU_RE = /data-sku=["']([^"']+)["']/i;

export async function fetchProductPage(env: Env, url: string): Promise<ScrapeResult> {
  const timeoutMs = parseIntEnv(env.REQUEST_TIMEOUT_MS, 15000);
  const res = await fetchWithTimeout(url, {
    timeoutMs,
    headers: {
      "user-agent": env.SCRAPE_USER_AGENT ?? "OdontoJfSync/1.0",
      accept: "text/html,application/xhtml+xml",
    },
  });
  const html = await res.text();
  const result = parseProductHtml(url, html);
  result.status_code = res.status;
  return result;
}

export function parseProductHtml(url: string, html: string): ScrapeResult {
  const slug = extractSlug(url);
  const meta = collectMeta(html);

  const title = readMeta(meta, "og:title") ?? extractTitleTag(html);
  const description = readMeta(meta, "og:description") ?? readMeta(meta, "description");
  const brand = readMeta(meta, "product:brand") ?? readMeta(meta, "og:brand");
  const stockStatus = readMeta(meta, "product:availability") ?? extractStockStatus(html);
  const images = collectImages(html, meta);

  const ld = collectJsonLd(html);
  const ldProduct = ld.find((item) => item && (item["@type"] === "Product" || item["@type"]?.includes?.("Product")));
  const ldSku = ldProduct?.sku ?? ldProduct?.mpn ?? null;
  const ldBrand = typeof ldProduct?.brand === "string" ? ldProduct.brand : ldProduct?.brand?.name ?? null;
  const ldCategory = typeof ldProduct?.category === "string" ? ldProduct.category : null;
  const ldDescription = ldProduct?.description ?? null;

  const nextData = extractNextData(html);
  const nextSku = pickFirst(nextData, ["sku", "codigo", "code", "product_code", "id"]);
  const nextCategory = pickFirst(nextData, ["categoria", "category", "categoryName"]);

  const hiddenSku = html.match(HIDDEN_SKU_RE)?.[1] ?? null;
  const dataSku = html.match(DATA_SKU_RE)?.[1] ?? null;

  const detectedSku = ldSku ?? hiddenSku ?? dataSku ?? nextSku ?? null;

  const category = buildCategoryList(html, ldCategory, nextCategory);

  return {
    url,
    slug,
    title: cleanText(title),
    brand: cleanText(brand ?? ldBrand ?? null),
    category,
    description: cleanText(description ?? ldDescription ?? null),
    images,
    stock_status: stockStatus,
    raw_meta: meta,
    detected_sku: detectedSku ? String(detectedSku) : null,
    fetched_at: new Date().toISOString(),
    status_code: 0,
  };
}

function collectMeta(html: string): Record<string, string> {
  const out: Record<string, string> = {};
  for (const match of html.matchAll(META_RE)) {
    const attrs = parseAttrs(match[1]);
    const key = attrs["property"] ?? attrs["name"] ?? attrs["itemprop"];
    const value = attrs["content"];
    if (key && value !== undefined) out[key.toLowerCase()] = value;
  }
  return out;
}

function parseAttrs(input: string): Record<string, string> {
  const out: Record<string, string> = {};
  for (const m of input.matchAll(ATTR_RE)) {
    out[m[1].toLowerCase()] = m[2];
  }
  return out;
}

function readMeta(meta: Record<string, string>, key: string): string | null {
  return meta[key.toLowerCase()] ?? null;
}

function extractTitleTag(html: string): string | null {
  const m = html.match(TITLE_RE);
  return m ? m[1].trim() : null;
}

function extractStockStatus(html: string): string | null {
  const lowered = html.toLowerCase();
  if (lowered.includes("produto esgotado") || lowered.includes("esgotado")) return "out_of_stock";
  if (lowered.includes("em estoque") || lowered.includes("disponível")) return "in_stock";
  return null;
}

function collectImages(html: string, meta: Record<string, string>): string[] {
  const images = new Set<string>();
  const og = readMeta(meta, "og:image");
  if (og) images.add(og);
  for (const match of html.matchAll(IMG_RE)) {
    const attrs = parseAttrs(match[1]);
    const src = attrs["src"] ?? attrs["data-src"];
    if (src && src.startsWith("http")) images.add(src);
  }
  return Array.from(images).slice(0, 20);
}

function collectJsonLd(html: string): any[] {
  const out: any[] = [];
  for (const match of html.matchAll(SCRIPT_LD_RE)) {
    try {
      const parsed = JSON.parse(match[1].trim());
      if (Array.isArray(parsed)) out.push(...parsed);
      else out.push(parsed);
    } catch {
      // ignore malformed JSON-LD
    }
  }
  return out;
}

function extractNextData(html: string): unknown | null {
  const match = html.match(SCRIPT_NEXT_DATA_RE);
  if (!match) return null;
  try {
    return JSON.parse(match[1].trim());
  } catch {
    return null;
  }
}

function pickFirst(obj: unknown, keys: string[]): string | null {
  if (!obj || typeof obj !== "object") return null;
  const stack: any[] = [obj];
  const seen = new Set<any>();
  while (stack.length > 0) {
    const cur = stack.shift();
    if (!cur || typeof cur !== "object" || seen.has(cur)) continue;
    seen.add(cur);
    for (const key of keys) {
      if (key in cur && (typeof cur[key] === "string" || typeof cur[key] === "number")) {
        return String(cur[key]);
      }
    }
    for (const v of Object.values(cur)) {
      if (v && typeof v === "object") stack.push(v);
    }
  }
  return null;
}

function buildCategoryList(html: string, ldCategory: string | null, nextCategory: string | null): string[] {
  if (ldCategory) return ldCategory.split(/\s*[>/]\s*/).filter(Boolean);
  if (nextCategory) return nextCategory.split(/\s*[>/]\s*/).filter(Boolean);
  // breadcrumb fallback: look for nav / ol structure
  const breadcrumb = html.match(/<(?:nav|ol)[^>]*breadcrumb[^>]*>([\s\S]*?)<\/(?:nav|ol)>/i);
  if (breadcrumb) {
    return Array.from(breadcrumb[1].matchAll(/<a[^>]*>([^<]+)<\/a>/g))
      .map((m) => m[1].trim())
      .filter(Boolean)
      .slice(0, 6);
  }
  return [];
}

function extractSlug(url: string): string {
  try {
    const u = new URL(url);
    const segments = u.pathname.split("/").filter(Boolean);
    return segments[segments.length - 1] ?? "";
  } catch {
    return "";
  }
}

function cleanText(value: string | null): string | null {
  if (!value) return null;
  const trimmed = value.replace(/\s+/g, " ").trim();
  return trimmed.length > 0 ? trimmed : null;
}
