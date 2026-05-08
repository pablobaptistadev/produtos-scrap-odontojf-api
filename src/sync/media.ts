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

/** Hosts whose URLs are accepted as-is (not file references we control). */
const PERMITTED_HOSTS = [
  "media.odontoapi.wpatomic.com.br",
  ".r2.dev",
  ".r2.cloudflarestorage.com",
  "dentalodontocirurgicajf.com.br",
  "youtube.com",
  "youtu.be",
  "ytimg.com",
  "player.vimeo.com",
  "vimeo.com",
];

function isPermittedHost(host: string): boolean {
  return PERMITTED_HOSTS.some((h) => host === h || host.endsWith(h));
}

/**
 * Find every URL that points at a downloadable asset (image or PDF) hosted on
 * a non-permitted domain. Looks at <img src="…">, every URL inside an <img
 * srcset="…">, and <a href="…pdf"> anchors.
 */
function collectExternalDescriptionUrls(html: string): string[] {
  const out: string[] = [];
  const seen = new Set<string>();
  const push = (raw: string | null | undefined) => {
    if (!raw) return;
    const u = raw.trim().replace(/^["']|["']$/g, "");
    if (!/^https?:\/\//i.test(u)) return;
    if (seen.has(u)) return;
    let host = "";
    try { host = new URL(u).hostname; } catch { return; }
    if (isPermittedHost(host)) return;
    seen.add(u);
    out.push(u);
  };

  // <img src="…">
  for (const m of html.matchAll(/<img\b[^>]*\ssrc="([^"]+)"/gi)) push(m[1]);
  // <img srcset="url1 1w, url2 2w, …"> — split by commas and drop the size token
  for (const m of html.matchAll(/<img\b[^>]*\ssrcset="([^"]+)"/gi)) {
    for (const part of m[1].split(",")) {
      const url = part.trim().split(/\s+/)[0];
      push(url);
    }
  }
  // <a href="…pdf">
  for (const m of html.matchAll(/<a\b[^>]*href="([^"]+\.pdf(?:\?[^"]*)?)"/gi)) push(m[1]);

  return out;
}

/**
 * Cleanup wrappers Gmail leaves behind when description text is copy-pasted
 * from email. We do TWO passes:
 *   1. Replace href="https://www.google.com/url?q=REAL&…" with href="REAL".
 *   2. Drop data-saferedirecturl="https://www.google.com/url?q=…" attributes
 *      entirely — they're informational only and keep an external URL alive
 *      in the persisted scrape_json.
 */
function unwrapGoogleSaferedirect(html: string): string {
  // Pass 1: rewrite the actual href.
  let out = html.replace(
    /href="https?:\/\/(?:www\.)?google\.com\/url\?[^"]*?q=([^&"]+)[^"]*"/gi,
    (_m, encoded) => {
      try { return `href="${decodeURIComponent(encoded)}"`; } catch { return `href=""`; }
    },
  );
  // Pass 2: strip the bookkeeping attribute the Gmail editor adds.
  out = out.replace(/\s+data-saferedirecturl="[^"]*"/gi, "");
  return out;
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

  // Enrich pdf_urls with any PDF links found inside description_html. This
  // is important on backfill: old scrapes captured before the parser learned
  // about description-embedded PDFs only have what initialData.files said.
  // Re-running this here lets the mirror pick them up.
  if (scrape.description_html) {
    const seen = new Set((scrape.pdf_urls ?? []).map((p) => p.url));
    const re = /<a\b[^>]*href="([^"]+\.pdf(?:\?[^"]*)?)"[^>]*>([\s\S]*?)<\/a>/gi;
    for (const m of scrape.description_html.matchAll(re)) {
      const url = m[1];
      if (!url || seen.has(url)) continue;
      seen.add(url);
      const label = m[2].replace(/<[^>]+>/g, " ").replace(/\s+/g, " ").trim() || "PDF";
      (scrape.pdf_urls ??= []).push({
        label: label.length > 80 ? label.slice(0, 80) + "…" : label,
        url,
      });
    }
  }

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

  // ---- harvest external file references inside description_html ----
  // The description sometimes embeds manufacturer images (yller.com.br,
  // fgmdentalgroup.com, …) inside <img src="…"> / <img srcset="…300w, …1024w">
  // and PDFs via <a href="…pdf">. Anything that isn't already on R2 or on a
  // permitted host (storefront / YouTube / Vimeo) gets pulled to R2 here so
  // the persisted description holds zero external file URLs.
  if (scrape.description_html) {
    const descUrls = collectExternalDescriptionUrls(scrape.description_html);
    let extraIdx = 0;
    for (const u of descUrls) {
      // Skip if already in our replacements queue (matches a prior mirror).
      if (replacements.some((r) => r.from === u)) continue;
      const key = `products/${safeSku}/scrape/description-extra/${extraIdx++}-${filenameFromUrl(u)}`;
      const r = await mirrorOne(u, key);
      if (r.status === "ok" && r.next) {
        if (r.next !== u) replacements.push({ from: u, to: r.next });
      } else if (r.status === "drop") {
        droppedUrls.push(u);
      }
      // transient: leave the URL in place — caller will fail the scrape
    }
  }

  // ---- rewrite description_html ----
  if (scrape.description_html) {
    let html = scrape.description_html;
    // Step 1: resolve google.com/url?q=… saferedirect wrappers added by Gmail
    // copy-paste. Replace href with the real q= destination.
    html = unwrapGoogleSaferedirect(html);
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
    // Strip <img src="blob:..."> — these are browser runtime references the
    // admin tool left behind (never resolvable outside the original session).
    html = html.replace(/<img\b[^>]*\ssrc="blob:[^"]*"[^>]*\/?>/gi, "");
    // Strip 'chrome-extension://…' text leaked from a Chrome PDF-viewer
    // extension during copy-paste.
    html = html.replace(/chrome-extension:\/\/[^\s<"]+/gi, "");
    // Drop <form action="external"> — these are leftover cart forms from
    // manufacturer storefronts pasted into the description. Useless to us.
    html = html.replace(/<form\b[^>]*>[\s\S]*?<\/form>/gi, "");
    // Rewrite anchors that still point at non-permitted hosts to href="#"
    // — preserves the visible text but removes the external link.
    html = html.replace(/<a\b([^>]*)\shref="(https?:\/\/[^"]+)"([^>]*)>/gi, (full, pre, url, post) => {
      let host = "";
      try { host = new URL(url).hostname; } catch { return full; }
      if (isPermittedHost(host)) return full;
      return `<a${pre} href="#"${post}>`;
    });
    // Strip any orphan http(s) URL that survived in plain text (e.g. paragraph
    // body that pasted a manufacturer link). Keep youtube/vimeo/storefront/R2.
    html = html.replace(/https?:\/\/[^\s<"]+/gi, (url) => {
      let host = "";
      try { host = new URL(url).hostname; } catch { return url; }
      return isPermittedHost(host) ? url : "";
    });
    scrape.description_html = html;

    // Re-derive plain-text description from the cleaned HTML so it doesn't
    // hold stale URLs from before the strip passes.
    scrape.description = html
      .replace(/<[^>]+>/g, " ")
      .replace(/&nbsp;/g, " ")
      .replace(/&amp;/g, "&")
      .replace(/\s+/g, " ")
      .trim() || null;
  }

  // ---- rewrite raw_meta string values ----
  // The scraper preserves OG / Twitter tags under raw_meta for reference. Any
  // URL there that we already mirrored should swap to the R2 copy; URLs we
  // dropped (4xx) should be cleared. As a last resort, any storefront-CDN URL
  // that didn't appear in our mirror plan (e.g. og:image points at a 800x800
  // version we never extracted) gets blanked — better to lose a meta tag than
  // to leave an external URL embedded in the persisted scrape.
  if (scrape.raw_meta && typeof scrape.raw_meta === "object") {
    for (const k of Object.keys(scrape.raw_meta)) {
      const v = scrape.raw_meta[k];
      if (typeof v !== "string") continue;
      let next = v;
      for (const r of replacements) {
        if (next.includes(r.from)) next = next.split(r.from).join(r.to);
      }
      for (const dead of droppedUrls) {
        if (next === dead || next.includes(dead)) {
          next = "";
          break;
        }
      }
      // Defensive: any URL on a non-permitted host gets blanked. raw_meta is
      // pure reference metadata — losing a tag is preferable to leaking an
      // external URL. We rerun the URL extractor and check each one.
      if (/https?:\/\//i.test(next)) {
        const urls = next.match(/https?:\/\/[^\s"'<>)]+/gi) ?? [];
        for (const u of urls) {
          let host = "";
          try { host = new URL(u).hostname; } catch { continue; }
          if (!isPermittedHost(host)) {
            next = "";
            break;
          }
        }
      }
      scrape.raw_meta[k] = next;
    }
  }

  return { scrape, result };
}
