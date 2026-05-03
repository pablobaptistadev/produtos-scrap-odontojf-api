import { describe, it, expect } from "vitest";
import { parseSitemapXml } from "../src/scraper/sitemap";

describe("parseSitemapXml", () => {
  it("extracts <loc> entries and slugs", () => {
    const xml = `<?xml version="1.0"?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
  <url><loc>https://dentalodontocirurgicajf.com.br/dispensador-pistola-universal-yller</loc></url>
  <url><loc>https://dentalodontocirurgicajf.com.br/porta-matriz-tofflemire-golgran</loc></url>
</urlset>`;
    const entries = parseSitemapXml(xml);
    expect(entries).toHaveLength(2);
    expect(entries[0].slug).toBe("dispensador-pistola-universal-yller");
    expect(entries[1].loc).toContain("porta-matriz-tofflemire-golgran");
  });

  it("dedupes repeat URLs and decodes entities", () => {
    const xml = `<urlset>
      <url><loc>https://x.com/foo&amp;bar</loc></url>
      <url><loc>https://x.com/foo&amp;bar</loc></url>
      <url><loc>https://x.com/baz</loc></url>
    </urlset>`;
    const entries = parseSitemapXml(xml);
    expect(entries).toHaveLength(2);
    expect(entries[0].loc).toBe("https://x.com/foo&bar");
  });
});
