/**
 * Resolve a SKU for a product.
 *
 * Strategy:
 *   1. Prefer SKU explicitly extracted from product page HTML (meta tag, JSON-LD, hidden input, data-sku).
 *   2. Fallback to a code-like trailing token in the URL slug
 *      (e.g. `dispensador-3m-...-hb004245674` → `hb004245674`).
 *   3. Last resort: use the slug itself, marked as provisional, so the ERP stage can
 *      replace it with the real SKU later.
 */

const TRAILING_CODE_RE = /-([a-z0-9]{6,})$/i;

export interface ResolvedSku {
  sku: string;
  provisional: boolean;
  source: "html" | "slug-tail" | "slug-fallback";
}

export function resolveSku(slug: string, htmlSku: string | null | undefined): ResolvedSku {
  if (htmlSku) {
    const cleaned = htmlSku.trim();
    if (cleaned.length > 0) return { sku: cleaned, provisional: false, source: "html" };
  }
  const match = slug.match(TRAILING_CODE_RE);
  if (match) {
    const candidate = match[1];
    if (/[a-z]/i.test(candidate) && /\d/.test(candidate)) {
      return { sku: candidate.toLowerCase(), provisional: true, source: "slug-tail" };
    }
  }
  return { sku: slug, provisional: true, source: "slug-fallback" };
}
