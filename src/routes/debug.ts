import type { Hono } from "hono";
import type { AppEnv } from "../env";
import { ApiError } from "../core";
import { fetchProductPage, parseProductHtml } from "../scraper/product-page";

/**
 * Inline debug routes — useful for development and one-shot inspection of the
 * scraper output without going through the SYNC_QUEUE pipeline.
 *
 * GET /debug/scrape?url=<full-product-url>
 *   → fetches the page right now and returns the parsed JSON (no DB writes,
 *     no queue events). Subject to api-key middleware like every other route.
 *
 * POST /debug/parse  (body: raw HTML, query: ?url=<url>)
 *   → parses the supplied HTML against the supplied URL and returns the JSON.
 *     Helpful for testing the parser against a saved fixture.
 */
export function registerDebugRoutes(app: Hono<AppEnv>): void {
  app.get("/debug/scrape", async (c) => {
    const target = c.req.query("url");
    if (!target) throw new ApiError(400, "query parameter `url` is required");
    if (!/^https?:\/\//.test(target)) {
      throw new ApiError(400, "`url` must be an absolute http(s) URL");
    }
    const result = await fetchProductPage(c.env, target);
    return c.json(result);
  });

  app.post("/debug/parse", async (c) => {
    const target = c.req.query("url");
    if (!target) throw new ApiError(400, "query parameter `url` is required");
    const html = await c.req.text();
    if (!html) throw new ApiError(400, "request body must contain HTML to parse");
    return c.json(parseProductHtml(target, html));
  });
}
