import type { Env } from "../env";
import { fetchWithTimeout, parseIntEnv } from "../core";
import type { MergedProduct } from "./merge";
import type { ScrapeResult } from "../scraper/product-page";

/**
 * Media stage — mirror every external image / PDF referenced by a merged
 * product into our R2 bucket and rewrite the URLs in place so downstream
 * (Woo, the painel UI) reads from a domain we control.
 *
 * Why: the source CDN (storage.googleapis.com/catalogo-mais-odonto.appspot.com)
 * is not under our control and individual files can be rotated, throttled or
 * removed. PDFs (manuals) are particularly perishable. Mirroring once and
 * serving from R2 (custom domain `media.odontoapi.wpatomic.com.br`) makes
 * the dataset self-contained.
 *
 * Storage layout:
 *   products/<sku>/images/<index>-<basename>           ← parent gallery
 *   products/<sku>/variations/<scrape_id>/<index>-<basename>
 *   products/<sku>/description-images/<index>-<basename>
 *   products/<sku>/pdfs/<index>-<basename>
 *
 * Idempotency: if R2 already has the key (HEAD returns metadata) we skip the
 * upload. Useful when re-running the stage on the same product after a
 * partial failure.
 *
 * Failures: per-file errors are pushed into `merged.warnings` but never abort
 * the stage. The merged_json is regravado mesmo com falhas parciais — the
 * URLs that did mirror are updated; the rest remain pointing at the source.
 */

export interface MediaMirrorResult {
  /** Total files attempted across this product. */
  attempted: number;
  /** Successfully uploaded (or already present, idempotent). */
  mirrored: number;
  /** Skipped because the URL was already on our public domain. */
  alreadyOurs: number;
  /** Failures (kept on the merged.warnings[] so callers see them). */
  failed: number;
}

interface MirrorContext {
  bucket: R2Bucket;
  publicBase: string;
  timeoutMs: number;
  userAgent: string;
  result: MediaMirrorResult;
  warnings: string[];
}

const PDF_KEY_RE = /\.pdf(?:$|\?)/i;

export async function mirrorProductMedia(
  env: Env,
  merged: MergedProduct,
): Promise<{ merged: MergedProduct; result: MediaMirrorResult }> {
  const bucket = env.MEDIA;
  const publicBase = (env.MEDIA_PUBLIC_BASE_URL ?? "").replace(/\/$/, "");
  const result: MediaMirrorResult = { attempted: 0, mirrored: 0, alreadyOurs: 0, failed: 0 };

  if (!bucket || !publicBase) {
    merged.warnings.push("media stage skipped: MEDIA bucket or MEDIA_PUBLIC_BASE_URL not configured");
    return { merged, result };
  }

  const ctx: MirrorContext = {
    bucket,
    publicBase,
    timeoutMs: parseIntEnv(env.REQUEST_TIMEOUT_MS, 15000),
    userAgent: env.SCRAPE_USER_AGENT ?? "OdontoJfSync/1.0",
    result,
    warnings: merged.warnings,
  };

  const sku = merged.sku;

  // Parent gallery
  for (let i = 0; i < merged.images.length; i++) {
    const img = merged.images[i];
    const key = buildKey(sku, "images", i, img.src);
    const next = await mirror(ctx, img.src, key);
    if (next) merged.images[i] = { src: next };
  }

  // Per-variation images (use scrape_id as namespace so re-mirroring stays stable)
  for (const v of merged.variations) {
    if (!v.image) continue;
    const key = buildKey(sku, `variations/${v.scrape_id}`, 0, v.image.src);
    const next = await mirror(ctx, v.image.src, key);
    if (next) v.image = { src: next };
  }

  // PDFs
  for (let i = 0; i < merged.pdf_urls.length; i++) {
    const pdf = merged.pdf_urls[i];
    const key = buildKey(sku, "pdfs", i, pdf.url);
    const next = await mirror(ctx, pdf.url, key);
    if (next) merged.pdf_urls[i] = { ...pdf, url: next };
  }

  // Update the same URLs in meta_data entries that reference them, so consumers
  // that read meta_data directly (Woo storefront, theme widgets) get the
  // mirrored copy too.
  for (const m of merged.meta_data) {
    if (m.key === "_odontojf_pdf_url") {
      const replaced = merged.pdf_urls.find((p) => urlsMatchByBasename(m.value, p.url));
      if (replaced) m.value = replaced.url;
    }
  }

  return { merged, result };
}

async function mirror(
  ctx: MirrorContext,
  url: string,
  key: string,
): Promise<string | null> {
  if (!url) return null;
  ctx.result.attempted++;

  // Already mirrored URL? Skip.
  if (url.startsWith(ctx.publicBase)) {
    ctx.result.alreadyOurs++;
    return url;
  }

  try {
    // Idempotency check via HEAD (R2 HEAD is cheap, single Class B op).
    const head = await ctx.bucket.head(key);
    if (head) {
      ctx.result.mirrored++;
      return `${ctx.publicBase}/${key}`;
    }

    // Try up to 3 times with exponential backoff. The storefront CDN
    // occasionally returns transient 5xx / connection resets — losing a
    // single image because of that would mean the user can never recover
    // the asset later (the original URL might rotate).
    let lastErr: string | null = null;
    for (let attempt = 0; attempt < 3; attempt++) {
      if (attempt > 0) await new Promise((r) => setTimeout(r, 200 * attempt * attempt));
      try {
        const res = await fetchWithTimeout(url, {
          timeoutMs: ctx.timeoutMs,
          headers: { "user-agent": ctx.userAgent },
        });
        if (!res.ok) {
          lastErr = `HTTP ${res.status}`;
          // 4xx is permanent — don't retry
          if (res.status >= 400 && res.status < 500) break;
          continue;
        }
        const contentType = res.headers.get("content-type") ?? guessContentType(key);
        const body = await res.arrayBuffer();
        if (body.byteLength === 0) {
          lastErr = "empty body";
          continue;
        }
        await ctx.bucket.put(key, body, {
          httpMetadata: {
            contentType,
            cacheControl: "public, max-age=31536000, immutable",
          },
          customMetadata: {
            sourceUrl: url,
            mirroredAt: new Date().toISOString(),
          },
        });
        ctx.result.mirrored++;
        return `${ctx.publicBase}/${key}`;
      } catch (err) {
        lastErr = err instanceof Error ? err.message : String(err);
      }
    }
    ctx.warnings.push(`media: failed to mirror ${url} → ${key}: ${lastErr ?? "unknown"}`);
    ctx.result.failed++;
    return null;
  } catch (err) {
    ctx.warnings.push(
      `media: failed to mirror ${url} → ${key}: ${err instanceof Error ? err.message : String(err)}`,
    );
    ctx.result.failed++;
    return null;
  }
}

function buildKey(sku: string, prefix: string, index: number, sourceUrl: string): string {
  const safeSku = sku.replace(/[^a-zA-Z0-9._-]/g, "_");
  const basename = filenameFromUrl(sourceUrl);
  return `products/${safeSku}/${prefix}/${index}-${basename}`;
}

function filenameFromUrl(url: string): string {
  try {
    const u = new URL(url);
    const seg = u.pathname.split("/").filter(Boolean).pop() ?? "file";
    // Strip query/fragment that snuck in (filenameFromUrl is called pre-encode).
    return seg.replace(/[^a-zA-Z0-9._-]/g, "_") || "file";
  } catch {
    return "file";
  }
}

function guessContentType(key: string): string {
  if (PDF_KEY_RE.test(key)) return "application/pdf";
  if (/\.png$/i.test(key)) return "image/png";
  if (/\.jpe?g$/i.test(key)) return "image/jpeg";
  if (/\.webp$/i.test(key)) return "image/webp";
  if (/\.gif$/i.test(key)) return "image/gif";
  if (/\.svg$/i.test(key)) return "image/svg+xml";
  return "application/octet-stream";
}

function urlsMatchByBasename(a: string, b: string): boolean {
  return filenameFromUrl(a) === filenameFromUrl(b);
}

/**
 * Mirror every external URL referenced inside a ScrapeResult to R2 and rewrite
 * the URLs in place. Called from runScrapeStage so the scrape_json that lands
 * on D1 already points at our domain — no external URL ever sticks around.
 *
 * Mirrors:
 *   - parent images               → products/<sku>/scrape/parent/<i>-<basename>
 *   - per-variation images        → products/<sku>/scrape/variations/<vid>/<i>-<basename>
 *   - description_images[]        → products/<sku>/scrape/description/<i>-<basename>
 *   - pdf_urls[]                  → products/<sku>/scrape/pdfs/<i>-<basename>
 *
 * Also does a string-level replace on description_html so any inline <img src>
 * or anchor href that pointed at the original CDN now points at R2.
 */
export async function mirrorScrapedMedia(
  env: Env,
  scrape: ScrapeResult,
  sku: string,
): Promise<{ scrape: ScrapeResult; result: MediaMirrorResult }> {
  const bucket = env.MEDIA;
  const publicBase = (env.MEDIA_PUBLIC_BASE_URL ?? "").replace(/\/$/, "");
  const result: MediaMirrorResult = { attempted: 0, mirrored: 0, alreadyOurs: 0, failed: 0 };
  const warnings: string[] = [];
  if (!bucket || !publicBase) {
    return { scrape, result };
  }

  const ctx: MirrorContext = {
    bucket,
    publicBase,
    timeoutMs: parseIntEnv(env.REQUEST_TIMEOUT_MS, 15000),
    userAgent: env.SCRAPE_USER_AGENT ?? "OdontoJfSync/1.0",
    result,
    warnings,
  };

  const safeSku = sku.replace(/[^a-zA-Z0-9._-]/g, "_");
  const replacements: Array<{ from: string; to: string }> = [];

  // ---- parent images ----
  for (let i = 0; i < (scrape.images ?? []).length; i++) {
    const img = scrape.images[i];
    if (!img?.src) continue;
    const key = `products/${safeSku}/scrape/parent/${i}-${filenameFromUrl(img.src)}`;
    const next = await mirror(ctx, img.src, key);
    if (next && next !== img.src) {
      replacements.push({ from: img.src, to: next });
      img.src = next;
    }
  }

  // ---- variation images ----
  for (const v of scrape.variations ?? []) {
    for (let i = 0; i < (v.images ?? []).length; i++) {
      const img = v.images[i];
      if (!img?.src) continue;
      const key = `products/${safeSku}/scrape/variations/${v.id}/${i}-${filenameFromUrl(img.src)}`;
      const next = await mirror(ctx, img.src, key);
      if (next && next !== img.src) {
        replacements.push({ from: img.src, to: next });
        img.src = next;
      }
    }
  }

  // ---- description-embed images ----
  const newDescImgs: string[] = [];
  for (let i = 0; i < (scrape.description_images ?? []).length; i++) {
    const src = scrape.description_images[i];
    if (!src) continue;
    const key = `products/${safeSku}/scrape/description/${i}-${filenameFromUrl(src)}`;
    const next = await mirror(ctx, src, key);
    if (next) {
      if (next !== src) replacements.push({ from: src, to: next });
      newDescImgs.push(next);
    } else {
      newDescImgs.push(src);
    }
  }
  scrape.description_images = newDescImgs;

  // ---- PDFs ----
  for (let i = 0; i < (scrape.pdf_urls ?? []).length; i++) {
    const pdf = scrape.pdf_urls[i];
    if (!pdf?.url) continue;
    const key = `products/${safeSku}/scrape/pdfs/${i}-${filenameFromUrl(pdf.url)}`;
    const next = await mirror(ctx, pdf.url, key);
    if (next && next !== pdf.url) {
      replacements.push({ from: pdf.url, to: next });
      pdf.url = next;
    }
  }

  // ---- rewrite description_html ----
  if (scrape.description_html && replacements.length > 0) {
    let html = scrape.description_html;
    for (const r of replacements) {
      // Replace exact and HTML-encoded variants of the URL.
      html = html.split(r.from).join(r.to);
      const enc = r.from.replace(/&/g, "&amp;");
      if (enc !== r.from) html = html.split(enc).join(r.to);
    }
    scrape.description_html = html;
  }

  return { scrape, result };
}
