import type { MiddlewareHandler } from "hono";
import type { AppEnv } from "../env";

export const corsMiddleware: MiddlewareHandler<AppEnv> = async (c, next) => {
  if (c.req.method === "OPTIONS") {
    return new Response(null, {
      status: 204,
      headers: {
        "access-control-allow-origin": "*",
        "access-control-allow-methods": "GET,POST,PUT,DELETE,OPTIONS",
        "access-control-allow-headers": "content-type,x-api-key,authorization",
        "access-control-max-age": "600",
      },
    });
  }
  await next();
  c.header("access-control-allow-origin", "*");
};
