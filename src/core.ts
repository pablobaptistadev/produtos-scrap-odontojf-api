export class ApiError extends Error {
  status: number;
  constructor(status: number, message: string) {
    super(message);
    this.status = status;
  }
}

export function jsonResponse(body: unknown, status = 200, extraHeaders: Record<string, string> = {}): Response {
  return new Response(JSON.stringify(body), {
    status,
    headers: {
      "content-type": "application/json; charset=utf-8",
      ...extraHeaders,
    },
  });
}

export function htmlResponse(html: string, status = 200): Response {
  return new Response(html, {
    status,
    headers: { "content-type": "text/html; charset=utf-8" },
  });
}

export function withCors(response: Response): Response {
  const headers = new Headers(response.headers);
  headers.set("access-control-allow-origin", "*");
  headers.set("access-control-allow-methods", "GET,POST,PUT,DELETE,OPTIONS");
  headers.set("access-control-allow-headers", "content-type,x-api-key,authorization");
  return new Response(response.body, { status: response.status, headers });
}

export async function parseJsonBody<T = unknown>(request: Request): Promise<T> {
  const text = await request.text();
  if (!text) return {} as T;
  try {
    return JSON.parse(text) as T;
  } catch {
    throw new ApiError(400, "invalid JSON body");
  }
}

export function safeJsonParse<T = unknown>(value: string | null | undefined): T | null {
  if (!value) return null;
  try {
    return JSON.parse(value) as T;
  } catch {
    return null;
  }
}

export function nowIso(): string {
  return new Date().toISOString();
}

export function normalizeSqliteTimestamp(value: string | null | undefined): string | null {
  if (!value) return null;
  if (value.includes("T")) return value;
  return value.replace(" ", "T") + "Z";
}

export function parseIntEnv(value: string | undefined, fallback: number): number {
  if (!value) return fallback;
  const parsed = Number.parseInt(value, 10);
  return Number.isFinite(parsed) ? parsed : fallback;
}

export async function fetchWithTimeout(url: string, init: RequestInit & { timeoutMs?: number } = {}): Promise<Response> {
  const { timeoutMs = 15000, ...rest } = init;
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), timeoutMs);
  try {
    return await fetch(url, { ...rest, signal: controller.signal });
  } finally {
    clearTimeout(timer);
  }
}

export function computeRetryDelaySeconds(attempts: number): number {
  // 30s, 60s, 120s, ... doubling each attempt, capped at 3600s (1h).
  const exp = Math.max(attempts - 1, 0);
  return Math.min(30 * Math.pow(2, exp), 3600);
}

export function deriveHttpStatus(httpStatus: number, hadTimeout: boolean): "ok" | "failed" | "timed_out" {
  if (hadTimeout) return "timed_out";
  if (httpStatus >= 200 && httpStatus < 300) return "ok";
  return "failed";
}

export function generateApiKey(): string {
  const arr = new Uint8Array(32);
  crypto.getRandomValues(arr);
  return Array.from(arr, (b) => b.toString(16).padStart(2, "0")).join("");
}

export function generateRequestId(): string {
  if (typeof crypto.randomUUID === "function") return crypto.randomUUID();
  const arr = new Uint8Array(16);
  crypto.getRandomValues(arr);
  return Array.from(arr, (b) => b.toString(16).padStart(2, "0")).join("");
}
