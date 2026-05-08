import type { Env } from "../env";
import { fetchWithTimeout, parseIntEnv } from "../core";
import { getAppState, setAppState } from "../db/repo";

/**
 * ERP integration — Space Informática "ecommerceapi" platform.
 *
 *   Docs   : http://45.227.82.180:8082/ecommerceapi/v1/documentacao/
 *   Login  : POST /autenticacao/entrar  body { "login": "...", "senha": "..." }
 *            → 200 OK { token, ... }
 *   Auth   : every other request carries `Authorization: SPACE <token>` (custom
 *            scheme — NOT Bearer).
 *   Product: GET /produto/:codigo  → returns the full cadastro (descricao,
 *            preco, estoque, peso, altura, comprimento, largura, midias[],
 *            categoria, fornecedorReferenciaCodigo, …).
 *   Stock  : GET /produto/:codigo/estoquepreco  → leaner endpoint for
 *            estoque/preco/precoPromocional/unidade.
 *
 * Token caching: tokens are valid for "X hours" (per the docs, value not
 * specified). We cache the most recently issued token in app_state under the
 * `erp_token` / `erp_token_issued_at` keys with a 30-minute soft TTL — well
 * inside whatever the real expiry is. If a request comes back 401 we drop the
 * cached token and try once more after a fresh login.
 */

const TOKEN_CACHE_KEY = "erp_token";
const TOKEN_TS_CACHE_KEY = "erp_token_issued_at";
const TOKEN_TTL_MS = 30 * 60 * 1000; // 30 minutes

export type ErpFetchResult =
  | { status: "ok"; data: unknown; httpStatus: number }
  | { status: "skipped"; reason: string }
  | { status: "failed"; reason: string; httpStatus?: number };

export async function fetchProductFromErp(env: Env, sku: string): Promise<ErpFetchResult> {
  const credsAvailable = hasErpCredentials(env);
  if (!credsAvailable) {
    return { status: "skipped", reason: "ERP credentials not configured (need ERP_API_TOKEN or ERP_LOGIN/ERP_SENHA)" };
  }
  const base = (env.ERP_BASE_URL ?? "").replace(/\/$/, "");
  if (!base) {
    return { status: "skipped", reason: "ERP_BASE_URL not configured" };
  }
  const timeoutMs = parseIntEnv(env.REQUEST_TIMEOUT_MS, 15000);

  // Up to 2 tries: first with cached/static token, second with a freshly-issued one.
  for (let attempt = 0; attempt < 2; attempt++) {
    const tokenResult = await getErpToken(env, base, timeoutMs, attempt > 0);
    if (tokenResult.status !== "ok") return tokenResult;

    const url = `${base}/produto/${encodeURIComponent(sku)}`;
    try {
      const res = await fetchWithTimeout(url, {
        timeoutMs,
        headers: {
          accept: "application/json",
          authorization: `SPACE ${tokenResult.token}`,
        },
      });
      const text = await res.text();
      if (res.status === 401 && attempt === 0 && !env.ERP_API_TOKEN) {
        // cached token rejected — drop it and retry with a fresh login
        await setAppState(env, TOKEN_CACHE_KEY, "");
        continue;
      }
      if (!res.ok) {
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
  // Static token wins.
  if (env.ERP_API_TOKEN && env.ERP_API_TOKEN !== "PLACEHOLDER_NOT_SET") {
    return { status: "ok", token: env.ERP_API_TOKEN };
  }
  if (!env.ERP_LOGIN || !env.ERP_SENHA) {
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

  // Login.
  try {
    const res = await fetchWithTimeout(`${base}/autenticacao/entrar`, {
      method: "POST",
      timeoutMs,
      headers: {
        "content-type": "application/json",
        accept: "application/json",
      },
      body: JSON.stringify({ login: env.ERP_LOGIN, senha: env.ERP_SENHA }),
    });
    const text = await res.text();
    if (!res.ok) {
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
