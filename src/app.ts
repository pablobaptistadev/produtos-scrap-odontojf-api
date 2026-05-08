import { Hono } from "hono";
import type { AppEnv } from "./env";
import { ApiError, jsonResponse } from "./core";
import { requestContextMiddleware } from "./middleware/request-context";
import { corsMiddleware } from "./middleware/cors";
import { apiKeyMiddleware } from "./middleware/api-key";
import { registerSystemRoutes } from "./routes/system";
import { registerSyncRoutes } from "./routes/sync";
import { registerProductRoutes } from "./routes/products";
import { registerQueueRoutes } from "./routes/queue";
import { registerMonitorRoutes } from "./routes/monitor";
import { registerDebugRoutes } from "./routes/debug";
import { registerDashboardRoutes } from "./routes/dashboard";
import { registerDashboardApiRoutes } from "./routes/dashboard-api";
import { registerDashboardProductRoutes } from "./routes/dashboard-product";
import { registerAdminRoutes } from "./routes/admin";

export const app = new Hono<AppEnv>();

app.use("*", corsMiddleware);
app.use("*", requestContextMiddleware);
app.use("*", apiKeyMiddleware);

app.onError((error, c) => {
  if (error instanceof ApiError) {
    return jsonResponse({ error: error.message, status: error.status }, error.status);
  }
  const requestId = c.get("requestId");
  const detail = error instanceof Error ? error.message : "unexpected error";
  return jsonResponse({ error: detail, request_id: requestId }, 500);
});

registerSystemRoutes(app);
registerSyncRoutes(app);
registerProductRoutes(app);
registerQueueRoutes(app);
registerMonitorRoutes(app);
registerDebugRoutes(app);
registerDashboardRoutes(app);
registerDashboardApiRoutes(app);
registerDashboardProductRoutes(app);
registerAdminRoutes(app);

app.notFound(() => jsonResponse({ error: "not found" }, 404));
