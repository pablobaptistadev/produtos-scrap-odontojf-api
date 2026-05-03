import type { Env } from "../env";
import { fetchWithTimeout, parseIntEnv } from "../core";

export interface SitemapEntry {
  loc: string;
  slug: string;
}

const LOC_RE = /<loc>\s*([^<\s][^<]*?)\s*<\/loc>/g;

function extractSlug(url: string): string {
  try {
    const u = new URL(url);
    const segments = u.pathname.split("/").filter(Boolean);
    return segments[segments.length - 1] ?? url;
  } catch {
    return url.replace(/^https?:\/\/[^/]+\//, "").replace(/\/$/, "");
  }
}

export async function fetchProductSitemap(env: Env): Promise<SitemapEntry[]> {
  const url = `${env.SCRAPE_BASE_URL.replace(/\/$/, "")}${env.SCRAPE_SITEMAP_PATH}`;
  const timeoutMs = parseIntEnv(env.REQUEST_TIMEOUT_MS, 15000);
  const res = await fetchWithTimeout(url, {
    timeoutMs,
    headers: {
      "user-agent": env.SCRAPE_USER_AGENT ?? "OdontoJfSync/1.0",
      accept: "application/xml,text/xml;q=0.9,*/*;q=0.8",
    },
  });
  if (!res.ok) {
    throw new Error(`sitemap fetch failed: HTTP ${res.status}`);
  }
  const xml = await res.text();
  return parseSitemapXml(xml);
}

export function parseSitemapXml(xml: string): SitemapEntry[] {
  const entries: SitemapEntry[] = [];
  const seen = new Set<string>();
  for (const match of xml.matchAll(LOC_RE)) {
    const loc = decodeXmlEntities(match[1].trim());
    if (!loc || seen.has(loc)) continue;
    seen.add(loc);
    entries.push({ loc, slug: extractSlug(loc) });
  }
  return entries;
}

function decodeXmlEntities(value: string): string {
  return value
    .replace(/&amp;/g, "&")
    .replace(/&lt;/g, "<")
    .replace(/&gt;/g, ">")
    .replace(/&quot;/g, '"')
    .replace(/&apos;/g, "'");
}
