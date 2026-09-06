import type { Env } from "../env";
import { parseIntEnv } from "../core";
import { getAppState, setAppState } from "../db/repo";
import { socketFetch } from "./socket-fetch";

/**
 * ERP integration — Space Informática "ecommerceapi" platform.
 *
 *   Docs   : http://45.227.82.180:8082/ecommerceapi/v1/documentacao/
 *   Login  : POST /autenticacao/entrar
 *            body { "login":"...", "senha":"...", "filialCodigo": <int> }
 *            → 200 OK { token, ... }
 *   Auth   : every other request carries `Authorization: SPACE <token>` (custom
 *            scheme — NOT Bearer).
 *   Product: GET /produto/:codigo  → returns the full cadastro (descricao,
 *            preco, estoque, peso, altura, comprimento, largura, midias[],
 *            categoria, fornecedorReferenciaCodigo, …).
 *
 * Token strategy (matches the storefront WP plugin):
 *   1. Use env.ERP_API_TOKEN when present (manually configured static token).
 *   2. Else use the cached token in app_state (refreshed via login on demand).
 *   3. On 401 from any product call, drop the cached token (and ignore the
 *      static one) and retry once with a freshly-issued token. This mirrors
 *      `auto_refresh_token_401` in the legacy plugin: we never let an expired
 *      token block the pipeline.
 *
 * Token cache: 30-minute soft TTL stored in app_state. The real expiry on the
 * ERP side isn't documented; the soft TTL keeps us well inside whatever it is.
 */

const TOKEN_CACHE_KEY = "erp_token";
const TOKEN_TS_CACHE_KEY = "erp_token_issued_at";
const TOKEN_TTL_MS = 30 * 60 * 1000; // 30 minutes

export type ErpFetchResult =
  | { status: "ok"; data: unknown; httpStatus: number }
  | { status: "skipped"; reason: string }
  | { status: "failed"; reason: string; httpStatus?: number };

export async function fetchProductFromErp(env: Env, sku: string): Promise<ErpFetchResult> {
  if (!hasErpCredentials(env)) {
    return { status: "skipped", reason: "ERP credentials not configured (need ERP_API_TOKEN or ERP_LOGIN/ERP_SENHA)" };
  }
  const base = (env.ERP_BASE_URL ?? "").replace(/\/$/, "");
  if (!base) {
    return { status: "skipped", reason: "ERP_BASE_URL not configured" };
  }
  const timeoutMs = parseIntEnv(env.ERP_TIMEOUT_MS, parseIntEnv(env.REQUEST_TIMEOUT_MS, 15000));

  // Up to 2 tries: first with cached/static token, second with a freshly-
  // issued one. The retry-on-401 path runs whenever ERP_LOGIN/ERP_SENHA are
  // available — even if ERP_API_TOKEN was preset (it might be expired).
  for (let attempt = 0; attempt < 2; attempt++) {
    const tokenResult = await getErpToken(env, base, timeoutMs, attempt > 0);
    if (tokenResult.status !== "ok") return tokenResult;

    const url = `${base}/produto/${encodeURIComponent(sku)}`;
    try {
      // Use socketFetch (raw TCP) instead of fetch() because the ERP serves on
      // port 8082, which Workers' fetch() refuses with Cloudflare error 1003.
      const res = await socketFetch(url, {
        method: "GET",
        timeoutMs,
        headers: {
          accept: "application/json",
          authorization: `SPACE ${tokenResult.token}`,
        },
      });
      const text = res.body;
      const ok = res.status >= 200 && res.status < 300;

      // 401: drop cached token AND retry once with a fresh login.
      if (res.status === 401 && attempt === 0 && (env.ERP_LOGIN && env.ERP_SENHA)) {
        await setAppState(env, TOKEN_CACHE_KEY, "");
        await setAppState(env, TOKEN_TS_CACHE_KEY, "");
        continue;
      }
      if (!ok) {
        return { status: "failed", reason: `HTTP ${res.status}: ${text.slice(0, 200)}`, httpStatus: res.status };
      }
      let data: unknown = text;
      try {
        data = JSON.parse(text);
      } catch {
        data = { raw: text };
      }
      return { status: "ok", data, httpStatus: res.status };
    } catch (err) {
      return { status: "failed", reason: err instanceof Error ? err.message : String(err) };
    }
  }
  return { status: "failed", reason: "ERP authentication retry exhausted" };
}

function hasErpCredentials(env: Env): boolean {
  if (env.ERP_API_TOKEN && env.ERP_API_TOKEN !== "PLACEHOLDER_NOT_SET") return true;
  if (env.ERP_LOGIN && env.ERP_SENHA) return true;
  return false;
}

type TokenResult =
  | { status: "ok"; token: string }
  | { status: "skipped"; reason: string }
  | { status: "failed"; reason: string; httpStatus?: number };

async function getErpToken(
  env: Env,
  base: string,
  timeoutMs: number,
  forceRefresh: boolean,
): Promise<TokenResult> {
  // Static token wins on the FIRST attempt only. Once we hit 401 the caller
  // sets forceRefresh=true and we fall through to a fresh login regardless
  // of whether ERP_API_TOKEN is set — the static token may have expired.
  if (!forceRefresh && env.ERP_API_TOKEN && env.ERP_API_TOKEN !== "PLACEHOLDER_NOT_SET") {
    return { status: "ok", token: env.ERP_API_TOKEN };
  }
  if (!env.ERP_LOGIN || !env.ERP_SENHA) {
    if (env.ERP_API_TOKEN && env.ERP_API_TOKEN !== "PLACEHOLDER_NOT_SET") {
      return { status: "ok", token: env.ERP_API_TOKEN };
    }
    return { status: "skipped", reason: "ERP_LOGIN/ERP_SENHA not configured" };
  }

  if (!forceRefresh) {
    const cached = await getAppState(env, TOKEN_CACHE_KEY);
    const cachedAt = await getAppState(env, TOKEN_TS_CACHE_KEY);
    if (cached && cachedAt) {
      const ageMs = Date.now() - new Date(cachedAt).getTime();
      if (ageMs >= 0 && ageMs < TOKEN_TTL_MS) {
        return { status: "ok", token: cached };
      }
    }
  }

  // Login. The Space ERP /autenticacao/entrar endpoint accepts an optional
  // filialCodigo; we send it when ERP_FILIAL_CODIGO is configured (matches
  // the storefront plugin).
  const body: Record<string, unknown> = {
    login: env.ERP_LOGIN,
    senha: env.ERP_SENHA,
  };
  const filialRaw = (env as any).ERP_FILIAL_CODIGO;
  if (filialRaw != null && String(filialRaw).trim() !== "") {
    const filial = Number.parseInt(String(filialRaw), 10);
    if (Number.isFinite(filial) && filial > 0) {
      body.filialCodigo = filial;
    }
  }

  try {
    const res = await socketFetch(`${base}/autenticacao/entrar`, {
      method: "POST",
      timeoutMs,
      headers: {
        "content-type": "application/json",
        accept: "application/json",
      },
      body: JSON.stringify(body),
    });
    const text = res.body;
    if (res.status < 200 || res.status >= 300) {
      return { status: "failed", reason: `ERP login HTTP ${res.status}: ${text.slice(0, 200)}`, httpStatus: res.status };
    }
    let data: any;
    try {
      data = JSON.parse(text);
    } catch {
      return { status: "failed", reason: "ERP login returned non-JSON" };
    }
    const token: string | undefined = data?.token;
    if (!token) {
      return { status: "failed", reason: `ERP login returned no token: ${JSON.stringify(data).slice(0, 200)}` };
    }
    await setAppState(env, TOKEN_CACHE_KEY, token);
    await setAppState(env, TOKEN_TS_CACHE_KEY, new Date().toISOString());
    return { status: "ok", token };
  } catch (err) {
    return { status: "failed", reason: err instanceof Error ? err.message : String(err) };
  }
}
