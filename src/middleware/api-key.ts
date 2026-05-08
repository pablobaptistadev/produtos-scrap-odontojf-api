import type { MiddlewareHandler } from "hono";
import type { AppEnv } from "../env";
import { ApiError } from "../core";

const PUBLIC_PATHS = new Set(["/", "/health", "/version"]);

export const apiKeyMiddleware: MiddlewareHandler<AppEnv> = async (c, next) => {
  const path = new URL(c.req.url).pathname;
  if (PUBLIC_PATHS.has(path)) {
    return next();
  }
  if (c.req.method === "OPTIONS") {
    return next();
  }
  const expected = c.env.WORKER_API_KEY;
  if (!expected) {
    // If unset, behave as open API but log a warning header — better than crashing first deploy.
    c.header("x-warning", "WORKER_API_KEY not configured; auth disabled");
    return next();
  }
  const provided = c.req.header("x-api-key") ?? c.req.query("api_key") ?? c.req.query("key") ?? extractBearer(c.req.header("authorization"));
  if (!provided || !timingSafeEqual(provided, expected)) {
    throw new ApiError(401, "invalid or missing api key");
  }
  return next();
};

function extractBearer(header: string | undefined): string | null {
  if (!header) return null;
  const m = header.match(/^Bearer\s+(.+)$/i);
  return m ? m[1].trim() : null;
}

function timingSafeEqual(a: string, b: string): boolean {
  if (a.length !== b.length) return false;
  let mismatch = 0;
  for (let i = 0; i < a.length; i++) mismatch |= a.charCodeAt(i) ^ b.charCodeAt(i);
  return mismatch === 0;
}
