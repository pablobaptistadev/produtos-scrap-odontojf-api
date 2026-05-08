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
  /** Transient failures (5xx, network) — caller may decide to retry. */
  failed: number;
  /** Permanent failures (4xx) — the source URL is gone, retry won't help. */
  permanent_failed: number;
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
  const result: MediaMirrorResult = { attempted: 0, mirrored: 0, alreadyOurs: 0, failed: 0, permanent_failed: 0 };

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

    // Try up to 3 times with exponential backoff. We split outcomes:
    //   - permanent (4xx): the storefront has removed/rotated this asset.
    //     Re-trying the whole scrape won't help. Increment permanent_failed
    //     and return null without flagging the row as a transient failure.
    //   - transient (5xx, network): retry up to 3 times, then count as
    //     transient `failed` so the caller can decide to retry the scrape.
    let lastErr: string | null = null;
    let permanent = false;
    for (let attempt = 0; attempt < 3; attempt++) {
      if (attempt > 0) await new Promise((r) => setTimeout(r, 200 * attempt * attempt));
      try {
        const res = await fetchWithTimeout(url, {
          timeoutMs: ctx.timeoutMs,
          headers: { "user-agent": ctx.userAgent },
        });
        if (!res.ok) {
          lastErr = `HTTP ${res.status}`;
          if (res.status >= 400 && res.status < 500) {
            permanent = true;
            break;
          }
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
    if (permanent) {
      ctx.warnings.push(`media: source rotated ${url} (${lastErr})`);
      ctx.result.permanent_failed++;
    } else {
      ctx.warnings.push(`media: transient failure ${url} → ${key}: ${lastErr ?? "unknown"}`);
      ctx.result.failed++;
    }
    return null;
  } catch (err) {
    ctx.warnings.push(
      `media: transient failure ${url} → ${key}: ${err instanceof Error ? err.message : String(err)}`,
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

function escapeForRegex(s: string): string {
  return s.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
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
  const result: MediaMirrorResult = { attempted: 0, mirrored: 0, alreadyOurs: 0, failed: 0, permanent_failed: 0 };
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
  // URLs that returned 4xx — to be also stripped out of description_html.
  const droppedUrls: string[] = [];

  /** mirror a single URL and return one of:
   *    "ok"        — replace original with `next`
   *    "drop"      — source is gone (4xx); strip from arrays
   *    "transient" — kept original (caller will fail the scrape) */
  async function mirrorOne(url: string, key: string): Promise<{ status: "ok" | "drop" | "transient"; next: string | null }> {
    const beforePerm = ctx.result.permanent_failed;
    const beforeFail = ctx.result.failed;
    const next = await mirror(ctx, url, key);
    if (next) return { status: "ok", next };
    if (ctx.result.permanent_failed > beforePerm) return { status: "drop", next: null };
    return { status: "transient", next: null };
  }

  // ---- parent images ----
  const newParent: typeof scrape.images = [];
  for (let i = 0; i < (scrape.images ?? []).length; i++) {
    const img = scrape.images[i];
    if (!img?.src) continue;
    const key = `products/${safeSku}/scrape/parent/${i}-${filenameFromUrl(img.src)}`;
    const r = await mirrorOne(img.src, key);
    if (r.status === "ok" && r.next) {
      if (r.next !== img.src) replacements.push({ from: img.src, to: r.next });
      newParent.push({ src: r.next, alt: img.alt ?? null });
    } else if (r.status === "drop") {
      droppedUrls.push(img.src); // source rotated → forget about this image
    } else {
      newParent.push(img); // transient: keep so caller can retry
    }
  }
  scrape.images = newParent;

  // ---- variation images ----
  for (const v of scrape.variations ?? []) {
    const newVarImgs: typeof v.images = [];
    for (let i = 0; i < (v.images ?? []).length; i++) {
      const img = v.images[i];
      if (!img?.src) continue;
      const key = `products/${safeSku}/scrape/variations/${v.id}/${i}-${filenameFromUrl(img.src)}`;
      const r = await mirrorOne(img.src, key);
      if (r.status === "ok" && r.next) {
        if (r.next !== img.src) replacements.push({ from: img.src, to: r.next });
        newVarImgs.push({ src: r.next, alt: img.alt ?? null });
      } else if (r.status === "drop") {
        droppedUrls.push(img.src);
      } else {
        newVarImgs.push(img);
      }
    }
    v.images = newVarImgs;
  }

  // ---- description-embed images ----
  const newDescImgs: string[] = [];
  for (let i = 0; i < (scrape.description_images ?? []).length; i++) {
    const src = scrape.description_images[i];
    if (!src) continue;
    const key = `products/${safeSku}/scrape/description/${i}-${filenameFromUrl(src)}`;
    const r = await mirrorOne(src, key);
    if (r.status === "ok" && r.next) {
      if (r.next !== src) replacements.push({ from: src, to: r.next });
      newDescImgs.push(r.next);
    } else if (r.status === "drop") {
      droppedUrls.push(src);
    } else {
      newDescImgs.push(src);
    }
  }
  scrape.description_images = newDescImgs;

  // ---- PDFs ----
  const newPdfs: typeof scrape.pdf_urls = [];
  for (let i = 0; i < (scrape.pdf_urls ?? []).length; i++) {
    const pdf = scrape.pdf_urls[i];
    if (!pdf?.url) continue;
    const key = `products/${safeSku}/scrape/pdfs/${i}-${filenameFromUrl(pdf.url)}`;
    const r = await mirrorOne(pdf.url, key);
    if (r.status === "ok" && r.next) {
      if (r.next !== pdf.url) replacements.push({ from: pdf.url, to: r.next });
      newPdfs.push({ ...pdf, url: r.next });
    } else if (r.status === "drop") {
      droppedUrls.push(pdf.url);
    } else {
      newPdfs.push(pdf);
    }
  }
  scrape.pdf_urls = newPdfs;

  // ---- rewrite description_html ----
  if (scrape.description_html) {
    let html = scrape.description_html;
    for (const r of replacements) {
      // Replace exact and HTML-encoded variants of the URL.
      html = html.split(r.from).join(r.to);
      const enc = r.from.replace(/&/g, "&amp;");
      if (enc !== r.from) html = html.split(enc).join(r.to);
    }
    // Strip <img> / <a href="..."> tags that referenced URLs we couldn't mirror
    // (4xx / source rotated). Better to render nothing than a broken image.
    for (const dead of droppedUrls) {
      const variants = [dead, dead.replace(/&/g, "&amp;")];
      for (const v of variants) {
        // Drop the entire <img …src="DEAD"…> tag, including srcset variants.
        html = html.replace(new RegExp(`<img[^>]*src="${escapeForRegex(v)}"[^>]*/?>`, "gi"), "");
        // Drop hyperlinks that wrapped the dead URL.
        html = html.replace(new RegExp(`<a[^>]*href="${escapeForRegex(v)}"[^>]*>[\\s\\S]*?</a>`, "gi"), "");
      }
    }
    scrape.description_html = html;
  }

  return { scrape, result };
}
