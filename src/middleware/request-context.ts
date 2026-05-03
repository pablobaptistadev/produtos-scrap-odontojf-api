import type { MiddlewareHandler } from "hono";
import type { AppEnv } from "../env";
import { generateRequestId } from "../core";

export const requestContextMiddleware: MiddlewareHandler<AppEnv> = async (c, next) => {
  const requestId = c.req.header("x-request-id") ?? generateRequestId();
  c.set("requestId", requestId);
  c.set("requestStart", Date.now());
  await next();
  c.header("x-request-id", requestId);
};
