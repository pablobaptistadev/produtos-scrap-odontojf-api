import type { Env } from "../env";
import { fetchWithTimeout, parseIntEnv } from "../core";

/**
 * Scraper for individual product pages on dentalodontocirurgicajf.com.br.
 *
 * The site is a Next.js app where the product card is rendered on the client.
 * Server-side HTML contains a `<script id="__NEXT_DATA__">` element with the
 * full product structure (title, description, images, brand, options/variations,
 * PDF files, etc.) under `props.pageProps.serverSideProps.initialData`.
 *
 * Pricing, stock and the human-readable code (Código do produto / SKU) come
 * from a separate API: `/api/product-specific-data?productId=<id>`. For
 * variable products (`type === "family"`), each variation has its own id
 * and gets its own `product-specific-data` call.
 *
 * The parser is split into:
 *   - parseProductHtml(url, html): extracts everything available in the HTML
 *     (uses __NEXT_DATA__, falls back to legacy DOM heuristics if absent).
 *     No network calls. Returns variations with empty price/sku slots.
 *   - fetchProductPage(env, url): runs parseProductHtml + enriches with calls
 *     to `/api/product-specific-data` for the parent and each variation.
 */

export interface ScrapeImage {
  /** largest available URL from the multi-size CDN object */
  src: string;
  alt: string | null;
}

export interface ScrapeDimensions {
  /** kg */
  weight: number | null;
  /** cm */
  length: number | null;
  /** cm */
  width: number | null;
  /** cm */
  height: number | null;
}

export interface ScrapeVariation {
  /** internal site id used by the specific-data API */
  id: string;
  /** human-readable code returned by /api/product-specific-data (Código do produto) */
  sku: string | null;
  /** label shown in the variation table (e.g. "A1", "DB-A3,5") */
  name: string;
  /** manufacturer / supplier reference (initialData.options[i].providerCode) */
  provider_code: string | null;
  price: string | null;
  price_text: string | null;
  stock_status: "in_stock" | "out_of_stock" | null;
  stock_qty: number | null;
  barcode: string | null;
  /** per-variation shipping dimensions (falls back to the parent's values) */
  dimensions: ScrapeDimensions;
  /** images attached to this specific variation (largest size each).
   *  Empty array means "fall back to the parent images" downstream. */
  images: ScrapeImage[];
}

export interface ScrapePdf {
  label: string;
  url: string;
}

export interface ScrapeResult {
  url: string;
  slug: string;
  type: "simple" | "variable";
  /** internal site id (e.g. "Bico64qhnV5r5HfprQZf") */
  id: string | null;
  title: string | null;
  brand: string | null;
  category: string[];
  short_description: string | null;
  description: string | null;
  description_html: string | null;
  /** primary product gallery (largest size per image) */
  images: ScrapeImage[];
  /** images embedded inside the rich description HTML */
  description_images: string[];
  stock_status: string | null;
  raw_meta: Record<string, string>;
  /** human-readable product code (Código do produto). Comes from API. */
  detected_sku: string | null;
  barcode: string | null;
  /** manufacturer / supplier reference (initialData.providerCode) */
  provider_code: string | null;
  /** parent shipping dimensions (kg / cm). Variations may override. */
  dimensions: ScrapeDimensions;
  /** simple-product fields (null on variable products) */
  price: string | null;
  price_text: string | null;
  installments: string | null;
  stock_qty: number | null;
  /** variable-product fields (empty on simple products) */
  variations: ScrapeVariation[];
  /** always-present extras */
  video_urls: string[];
  pdf_urls: ScrapePdf[];
  fetched_at: string;
  status_code: number;
  /** true when the result was enriched via product-specific-data API */
  api_enriched: boolean;
}

const NEXT_DATA_RE = /<script[^>]*id=["']__NEXT_DATA__["'][^>]*>([\s\S]*?)<\/script>/i;
const META_RE = /<meta\s+([^>]+?)\/?>/gi;
const ATTR_RE = /(\w[\w:-]*)\s*=\s*"([^"]*)"/g;
const TITLE_RE = /<title[^>]*>([\s\S]*?)<\/title>/i;
const IMG_RE = /<img\s+([^>]*?)\/?>/gi;

const HTML_ENTITIES: Record<string, string> = {
  amp: "&", lt: "<", gt: ">", quot: '"', apos: "'", nbsp: " ",
  ccedil: "ç", Ccedil: "Ç", atilde: "ã", Atilde: "Ã", otilde: "õ", Otilde: "Õ",
  aacute: "á", Aacute: "Á", eacute: "é", Eacute: "É", iacute: "í", Iacute: "Í",
  oacute: "ó", Oacute: "Ó", uacute: "ú", Uacute: "Ú",
  agrave: "à", Agrave: "À", egrave: "è", Egrave: "È",
  acirc: "â", Acirc: "Â", ecirc: "ê", Ecirc: "Ê", icirc: "î", Icirc: "Î",
  ocirc: "ô", Ocirc: "Ô", ucirc: "û", Ucirc: "Û",
  auml: "ä", Auml: "Ä", euml: "ë", Euml: "Ë", iuml: "ï", Iuml: "Ï",
  ouml: "ö", Ouml: "Ö", uuml: "ü", Uuml: "Ü",
  ntilde: "ñ", Ntilde: "Ñ",
};

interface SpecificData {
  id?: string;
  internalId?: string | null;
  price?: number | null;
  formattedPrice?: string | null;
  units?: number | null;
  available?: boolean;
  options?: unknown[];
}

interface NextDataInitial {
  id?: string;
  title?: string;
  legend?: string;
  brand?: string;
  slug?: string;
  type?: string;
  description?: string;
  descriptionFormatted?: string;
  barcode?: string;
  providerCode?: string;
  weight?: number | null;
  length?: number | null;
  width?: number | null;
  height?: number | null;
  youtubeVideoId?: string;
  images?: Array<Record<string, string>>;
  files?: Array<{ name?: string; url?: string; category?: string; otherCategory?: string; extension?: string }>;
  options?: NextDataOption[];
  categories?: string[];
}

interface NextDataOption {
  id: string;
  titleInFamily?: string;
  title?: string;
  legend?: string;
  description?: string;
  brand?: string;
  slug?: string;
  barcode?: string;
  providerCode?: string;
  weight?: number | null;
  length?: number | null;
  width?: number | null;
  height?: number | null;
  images?: Array<Record<string, string>>;
  files?: Array<{ name?: string; url?: string; category?: string; otherCategory?: string; extension?: string }>;
  youtubeVideoId?: string;
}

// ---------- entry points ----------

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
  if (result.id) {
    await enrichWithSpecificData(env, result, timeoutMs);
  }
  return result;
}

export function parseProductHtml(url: string, html: string): ScrapeResult {
  const slug = extractSlug(url);
  const meta = collectMeta(html);
  const next = extractNextData(html);

  const initial = readInitialData(next);
  if (initial) {
    return parseFromNextData(url, slug, initial, next, html, meta);
  }
  // Fallback: use the legacy DOM-rendered approach (works for caches / stripped HTML).
  return parseFromRenderedDom(url, slug, html, meta);
}

/**
 * Issue parallel calls to `/api/product-specific-data?productId=<id>` for the
 * parent product and (when variable) each variation, populating sku/price/stock.
 * Mutates `result` in place and returns it.
 */
export async function enrichWithSpecificData(
  env: Env,
  result: ScrapeResult,
  timeoutMs: number,
): Promise<ScrapeResult> {
  if (!result.id) return result;
  const baseApi = `${(env.SCRAPE_BASE_URL ?? "").replace(/\/$/, "")}/api/product-specific-data`;
  if (!baseApi.startsWith("http")) return result;

  // Fetch parent + variations concurrently.
  const ids = [result.id, ...result.variations.map((v) => v.id)];
  const responses = await Promise.allSettled(
    ids.map((id) => fetchSpecificData(baseApi, id, env, timeoutMs)),
  );

  const parent = responses[0];
  if (parent.status === "fulfilled" && parent.value) {
    applyParentSpecific(result, parent.value);
  }

  for (let i = 0; i < result.variations.length; i++) {
    const r = responses[i + 1];
    if (r.status === "fulfilled" && r.value) {
      applyVariationSpecific(result.variations[i], r.value);
    }
  }

  // Refine top-level detected_sku: simple → parent.internalId, variable → first variation
  if (result.type === "simple" && !result.detected_sku) {
    result.detected_sku = parent.status === "fulfilled" ? parent.value?.internalId ?? null : null;
  } else if (result.type === "variable" && result.variations.length > 0) {
    const firstWithSku = result.variations.find((v) => v.sku);
    if (firstWithSku) result.detected_sku = firstWithSku.sku;
  }

  result.api_enriched = true;
  return result;
}

async function fetchSpecificData(
  baseApi: string,
  productId: string,
  env: Env,
  timeoutMs: number,
): Promise<SpecificData | null> {
  try {
    const res = await fetchWithTimeout(`${baseApi}?productId=${encodeURIComponent(productId)}`, {
      timeoutMs,
      headers: {
        accept: "application/json",
        "user-agent": env.SCRAPE_USER_AGENT ?? "OdontoJfSync/1.0",
      },
    });
    if (!res.ok) return null;
    return (await res.json()) as SpecificData;
  } catch {
    return null;
  }
}

function applyParentSpecific(result: ScrapeResult, sp: SpecificData): void {
  if (sp.internalId) result.detected_sku = sp.internalId;
  if (result.type === "simple") {
    if (typeof sp.price === "number") result.price = sp.price.toFixed(2);
    if (sp.formattedPrice) result.price_text = decodeHtmlEntities(sp.formattedPrice);
    if (typeof sp.units === "number") result.stock_qty = sp.units;
    if (sp.available === false) result.stock_status = "out_of_stock";
    else if (sp.available === true) result.stock_status = "in_stock";
  }
}

function applyVariationSpecific(variation: ScrapeVariation, sp: SpecificData): void {
  if (sp.internalId) variation.sku = sp.internalId;
  if (typeof sp.price === "number") variation.price = sp.price.toFixed(2);
  if (sp.formattedPrice) variation.price_text = decodeHtmlEntities(sp.formattedPrice);
  if (typeof sp.units === "number") variation.stock_qty = sp.units;
  if (sp.available === false) {
    variation.stock_status = "out_of_stock";
    variation.stock_qty = variation.stock_qty ?? 0;
  } else if (sp.available === true) {
    variation.stock_status = "in_stock";
  }
}

// ---------- parsers ----------

function parseFromNextData(
  url: string,
  slug: string,
  initial: NextDataInitial,
  next: any,
  html: string,
  meta: Record<string, string>,
): ScrapeResult {
  const isVariable = initial.type === "family"
    && Array.isArray(initial.options)
    && initial.options.length > 0;

  const description_html =
    decodeHtmlEntities(initial.description ?? initial.descriptionFormatted ?? "") || null;
  const description = description_html ? htmlToText(description_html) : null;
  const description_images = description_html
    ? filterImageNoise(collectImagesFromHtml(description_html))
    : [];

  const images = mapImages(initial.images ?? []);

  // PDFs from init.files (parent only — variations rarely override) + any
  // PDF links embedded inside the description HTML (e.g. "Link para PDF
  // Catálogo" anchors that aren't surfaced through initialData.files).
  const pdf_urls = mergePdfLists(
    collectPdfFiles(initial.files),
    collectPdfsFromHtml(description_html ?? ""),
  );

  // Videos: youtubeVideoId field + iframes embedded in description
  const video_urls = collectVideoUrls(initial.youtubeVideoId, description_html);

  // Parent dimensions (used as fallback for variations that don't override).
  const parentDimensions = readDimensions(initial);

  // Variations
  const variations: ScrapeVariation[] = isVariable
    ? (initial.options ?? []).map((opt) => ({
        id: opt.id,
        sku: null,
        name: decodeHtmlEntities(opt.titleInFamily ?? opt.title ?? "").trim(),
        provider_code: nonEmpty(opt.providerCode) ?? null,
        price: null,
        price_text: null,
        stock_status: null,
        stock_qty: null,
        barcode: nonEmpty(opt.barcode) ?? null,
        dimensions: mergeDimensions(parentDimensions, readDimensions(opt)),
        images: mapImages(opt.images ?? []),
      }))
    : [];

  // Categories: resolve IDs via initialProps.categories lookup.
  const categories = resolveCategoryNames(initial.categories ?? [], next);

  return {
    url,
    slug,
    type: isVariable ? "variable" : "simple",
    id: initial.id ?? null,
    title: cleanText(decodeHtmlEntities(initial.title ?? "")) ?? null,
    brand: cleanText(decodeHtmlEntities(initial.brand ?? "")) ?? null,
    category: categories,
    short_description: cleanText(decodeHtmlEntities(initial.legend ?? "")) ?? null,
    description,
    description_html,
    images,
    description_images,
    stock_status: null, // populated by enrichWithSpecificData
    raw_meta: meta,
    detected_sku: null, // populated by enrichWithSpecificData
    barcode: nonEmpty(initial.barcode) ?? null,
    provider_code: nonEmpty(initial.providerCode) ?? null,
    dimensions: parentDimensions,
    price: null,
    price_text: null,
    installments: null,
    stock_qty: null,
    variations,
    video_urls,
    pdf_urls,
    fetched_at: new Date().toISOString(),
    status_code: 0,
    api_enriched: false,
  };
}

/**
 * Legacy fallback when __NEXT_DATA__ is absent (e.g. cached / stripped HTML).
 * Mirrors the earlier rendered-DOM heuristics so the pipeline degrades gracefully.
 */
function parseFromRenderedDom(
  url: string,
  slug: string,
  html: string,
  meta: Record<string, string>,
): ScrapeResult {
  const titleMeta = readMeta(meta, "og:title") ?? extractTitleTag(html);
  const descMeta = readMeta(meta, "og:description") ?? readMeta(meta, "description");
  const ogImage = readMeta(meta, "og:image");
  const fetched_at = new Date().toISOString();

  return {
    url,
    slug,
    type: "simple",
    id: null,
    title: cleanText(titleMeta),
    brand: cleanText(readMeta(meta, "product:brand") ?? readMeta(meta, "og:brand")),
    category: [],
    short_description: null,
    description: cleanText(descMeta),
    description_html: null,
    images: ogImage ? [{ src: ogImage, alt: null }] : [],
    description_images: [],
    stock_status: extractStockStatus(html),
    raw_meta: meta,
    detected_sku: null,
    barcode: null,
    provider_code: null,
    dimensions: { weight: null, length: null, width: null, height: null },
    price: null,
    price_text: null,
    installments: null,
    stock_qty: null,
    variations: [],
    video_urls: collectVideoUrls("", html),
    pdf_urls: [],
    fetched_at,
    status_code: 0,
    api_enriched: false,
  };
}

// ---------- helpers ----------

function readInitialData(next: any): NextDataInitial | null {
  const initial = next?.props?.pageProps?.serverSideProps?.initialData;
  if (initial && typeof initial === "object") return initial as NextDataInitial;
  return null;
}

function extractNextData(html: string): unknown | null {
  const match = html.match(NEXT_DATA_RE);
  if (!match) return null;
  try {
    return JSON.parse(match[1].trim());
  } catch {
    return null;
  }
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

function mapImages(items: Array<Record<string, string>>): ScrapeImage[] {
  const seen = new Set<string>();
  const out: ScrapeImage[] = [];
  for (const item of items) {
    if (!item || typeof item !== "object") continue;
    const url = pickLargestSize(item);
    if (!url || seen.has(url)) continue;
    seen.add(url);
    out.push({ src: url, alt: null });
  }
  return out;
}

/** From an object like `{ "50x50": "...", "800x800": "...", "1600x1600": "..." }` pick the URL with largest area. */
function pickLargestSize(obj: Record<string, string>): string | null {
  let best: string | null = null;
  let bestArea = -1;
  for (const [key, value] of Object.entries(obj)) {
    if (typeof value !== "string" || !value.startsWith("http")) continue;
    const m = key.match(/^(\d+)x(\d+)$/);
    const area = m ? Number(m[1]) * Number(m[2]) : 1;
    if (area > bestArea) {
      bestArea = area;
      best = value;
    }
  }
  return best;
}

function collectImagesFromHtml(html: string): string[] {
  const out: string[] = [];
  const seen = new Set<string>();
  for (const match of html.matchAll(IMG_RE)) {
    const attrs = parseAttrs(match[1]);
    const src = attrs["src"] ?? attrs["data-src"];
    if (!src || !src.startsWith("http") || seen.has(src)) continue;
    seen.add(src);
    out.push(src);
  }
  return out;
}

const IMAGE_NOISE_PATTERNS = [
  /\/payment\//i,
  /\/selos\//i,
  /odonto-cirurgica-jf\.png$/i,
];

function filterImageNoise(images: string[]): string[] {
  return images.filter((src) => !IMAGE_NOISE_PATTERNS.some((p) => p.test(src)));
}

function collectPdfFiles(files: NextDataInitial["files"]): ScrapePdf[] {
  if (!Array.isArray(files)) return [];
  const out: ScrapePdf[] = [];
  for (const f of files) {
    if (!f?.url) continue;
    const ext = (f.extension ?? "").toLowerCase();
    if (ext && ext !== "pdf") continue;
    if (!ext && !f.url.toLowerCase().endsWith(".pdf")) continue;
    out.push({
      label: prettifyFileLabel(f.category ?? f.otherCategory ?? f.name ?? "PDF"),
      url: f.url,
    });
  }
  return out;
}

function prettifyFileLabel(raw: string): string {
  if (!raw) return "PDF";
  if (raw.length <= 1) return raw.toUpperCase();
  return raw.charAt(0).toUpperCase() + raw.slice(1);
}

const PDF_ANCHOR_RE = /<a\b[^>]*href="([^"]+\.pdf(?:\?[^"]*)?)"[^>]*>([\s\S]*?)<\/a>/gi;

/** Find PDF links inside arbitrary HTML (descriptions, info panels, etc.). */
function collectPdfsFromHtml(html: string): ScrapePdf[] {
  if (!html) return [];
  const out: ScrapePdf[] = [];
  const seen = new Set<string>();
  for (const m of html.matchAll(PDF_ANCHOR_RE)) {
    const url = m[1];
    if (!url || seen.has(url)) continue;
    seen.add(url);
    const label = htmlToText(m[2]) || "PDF";
    out.push({ label: label.length > 80 ? label.slice(0, 80) + "…" : label, url });
  }
  return out;
}

/** Merge two PDF lists deduping by URL (first occurrence wins). */
function mergePdfLists(...lists: ScrapePdf[][]): ScrapePdf[] {
  const out: ScrapePdf[] = [];
  const seen = new Set<string>();
  for (const list of lists) {
    for (const pdf of list) {
      if (!pdf?.url || seen.has(pdf.url)) continue;
      seen.add(pdf.url);
      out.push(pdf);
    }
  }
  return out;
}

function collectVideoUrls(youtubeVideoId: string | undefined, descriptionHtml: string | null): string[] {
  const set = new Set<string>();
  if (youtubeVideoId && /^[\w-]+$/.test(youtubeVideoId)) {
    set.add(`https://www.youtube.com/watch?v=${youtubeVideoId}`);
  }
  if (descriptionHtml) {
    // YouTube — normalise embed/watch/short forms to watch URL.
    const ytRe = /https?:\/\/(?:www\.)?(?:youtube\.com\/(?:embed\/|watch\?v=)|youtu\.be\/)([\w-]+)/gi;
    for (const m of descriptionHtml.matchAll(ytRe)) {
      set.add(`https://www.youtube.com/watch?v=${m[1]}`);
    }
    // Vimeo — accept both vimeo.com/<id> and player.vimeo.com/video/<id> forms.
    const vimeoRe = /https?:\/\/(?:player\.)?vimeo\.com\/(?:video\/)?([0-9]+)/gi;
    for (const m of descriptionHtml.matchAll(vimeoRe)) {
      set.add(`https://vimeo.com/${m[1]}`);
    }
  }
  return Array.from(set);
}

function resolveCategoryNames(ids: string[], next: any): string[] {
  if (!ids?.length) return [];
  const root = next?.props?.pageProps?.initialProps?.categories;
  if (!root) return [];
  const lookup = new Map<string, { title?: string; slug?: string; parentId?: string | null }>();
  walkCategories(root, lookup);
  return ids
    .map((id) => lookup.get(id)?.title ?? null)
    .filter((t): t is string => Boolean(t))
    .map(decodeHtmlEntities)
    .map((t) => t.trim())
    .filter(Boolean);
}

function walkCategories(node: any, out: Map<string, { title?: string; slug?: string; parentId?: string | null }>): void {
  if (node && typeof node === "object") {
    if (typeof node.id === "string" && node.id) {
      out.set(node.id, { title: node.title, slug: node.slug, parentId: node.parentId ?? null });
    }
    if (Array.isArray(node)) {
      for (const v of node) walkCategories(v, out);
    } else {
      for (const v of Object.values(node)) walkCategories(v, out);
    }
  }
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

function decodeHtmlEntities(s: string | null | undefined): string {
  if (!s) return "";
  return s
    .replace(/&#(\d+);/g, (_, code) => String.fromCharCode(Number(code)))
    .replace(/&#x([0-9a-fA-F]+);/g, (_, code) => String.fromCharCode(parseInt(code, 16)))
    .replace(/&([a-zA-Z]+);/g, (_, name) => HTML_ENTITIES[name] ?? `&${name};`);
}

function cleanText(value: string | null | undefined): string | null {
  if (!value) return null;
  const trimmed = value.replace(/\s+/g, " ").trim();
  return trimmed.length > 0 ? trimmed : null;
}

function htmlToText(html: string): string {
  return decodeHtmlEntities(html.replace(/<[^>]+>/g, " "))
    .replace(/\s+/g, " ")
    .trim();
}

function nonEmpty(value: string | null | undefined): string | null {
  if (value == null) return null;
  const trimmed = String(value).trim();
  return trimmed.length > 0 ? trimmed : null;
}

function num(value: unknown): number | null {
  if (value == null || value === "") return null;
  const n = typeof value === "number" ? value : Number(value);
  return Number.isFinite(n) ? n : null;
}

function readDimensions(src: { weight?: number | null; length?: number | null; width?: number | null; height?: number | null }): ScrapeDimensions {
  return {
    weight: num(src.weight),
    length: num(src.length),
    width: num(src.width),
    height: num(src.height),
  };
}

/** Take field-by-field overrides from variation; fall back to parent for any
 *  missing value (null/undefined OR 0 — physical products never legitimately
 *  weigh 0kg or measure 0cm, so a zero is the storefront's "unspecified"). */
function mergeDimensions(parent: ScrapeDimensions, child: ScrapeDimensions): ScrapeDimensions {
  const fb = (c: number | null, p: number | null): number | null =>
    c != null && c !== 0 ? c : p;
  return {
    weight: fb(child.weight, parent.weight),
    length: fb(child.length, parent.length),
    width: fb(child.width, parent.width),
    height: fb(child.height, parent.height),
  };
}
