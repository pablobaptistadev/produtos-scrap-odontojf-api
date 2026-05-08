import { connect } from "cloudflare:sockets";

/**
 * Manual HTTP/1.1 over a raw TCP socket.
 *
 * Cloudflare Workers' `fetch()` only allows outbound HTTP on a fixed set of
 * ports (80/443/8080/8443/2052/2082/2083/2086/2087/2095/2096/2880). The ERP
 * we integrate with serves its API on :8082, which is outside that list and
 * the runtime returns Cloudflare error 1003. The `cloudflare:sockets`
 * `connect()` API lets us open a TCP socket to any port — we just need to
 * speak HTTP/1.1 ourselves.
 *
 * This implementation only handles the cases we need:
 *   - methods: GET, POST
 *   - bodies: optional UTF-8 string (we set Content-Length / Content-Type)
 *   - response: status code + headers + entire body buffered into a string
 *
 * It does NOT handle: chunked transfer-encoding, gzip/deflate, redirects,
 * keep-alive reuse. The Space ERP returns plain `Content-Length` JSON so
 * none of those matter for us.
 */

export interface SocketFetchOptions {
  method?: "GET" | "POST" | "PUT" | "DELETE";
  headers?: Record<string, string>;
  body?: string;
  /** Per-request timeout. Default 15s. */
  timeoutMs?: number;
}

export interface SocketFetchResponse {
  status: number;
  headers: Record<string, string>;
  body: string;
  /** Convenience: returns the parsed JSON or throws if not JSON. */
  json<T = unknown>(): T;
}

export async function socketFetch(url: string, opts: SocketFetchOptions = {}): Promise<SocketFetchResponse> {
  const u = new URL(url);
  const isHttps = u.protocol === "https:";
  const port = u.port ? Number.parseInt(u.port, 10) : (isHttps ? 443 : 80);
  const host = u.hostname;
  const path = (u.pathname || "/") + (u.search || "");

  const method = opts.method ?? "GET";
  const headers: Record<string, string> = {
    Host: u.port && [80, 443].indexOf(port) === -1 ? `${host}:${port}` : host,
    "User-Agent": "OdontoJfSync/1.0 (+https://odontoapi.wpatomic.com.br)",
    Accept: "application/json",
    Connection: "close",
    ...(opts.headers ?? {}),
  };

  let bodyBytes: Uint8Array | null = null;
  if (opts.body != null) {
    bodyBytes = new TextEncoder().encode(opts.body);
    if (!hasHeader(headers, "Content-Length")) headers["Content-Length"] = String(bodyBytes.byteLength);
    if (!hasHeader(headers, "Content-Type")) headers["Content-Type"] = "application/json; charset=utf-8";
  }

  // Build the request line + headers as a single ASCII buffer.
  const lines: string[] = [`${method} ${path} HTTP/1.1`];
  for (const [k, v] of Object.entries(headers)) lines.push(`${k}: ${v}`);
  lines.push("", ""); // empty line ends headers
  const headerBytes = new TextEncoder().encode(lines.join("\r\n"));

  const socket = connect(
    { hostname: host, port },
    { secureTransport: isHttps ? "on" : "off", allowHalfOpen: true },
  );

  // Abort on timeout — close socket so the awaited operations reject.
  const timeoutMs = opts.timeoutMs ?? 15_000;
  let timedOut = false;
  const timer = setTimeout(() => {
    timedOut = true;
    try { socket.close(); } catch { /* noop */ }
  }, timeoutMs);

  try {
    // Workers TCP sockets resolve `socket.opened` once the connection is
    // established. Skipping this lets writes silently fail if the connection
    // races with the EOF read.
    await socket.opened;

    const writer = socket.writable.getWriter();
    await writer.write(headerBytes);
    if (bodyBytes) await writer.write(bodyBytes);
    // Release the writer but do NOT close it — closing the writer would tear
    // down the entire socket on the Workers runtime, even with allowHalfOpen.
    // Connection: close in the request headers tells the server it can end
    // the response with EOF, and reader.read() will see {done:true} naturally.
    writer.releaseLock();

    // Read the whole response.
    const reader = socket.readable.getReader();
    const chunks: Uint8Array[] = [];
    while (true) {
      const { value, done } = await reader.read();
      if (done) break;
      if (value) chunks.push(value);
    }

    const total = chunks.reduce((n, c) => n + c.byteLength, 0);
    const buf = new Uint8Array(total);
    let off = 0;
    for (const c of chunks) { buf.set(c, off); off += c.byteLength; }

    return parseHttpResponse(buf);
  } catch (err) {
    if (timedOut) {
      throw new Error(`socketFetch timeout after ${timeoutMs}ms`);
    }
    throw err;
  } finally {
    clearTimeout(timer);
    try { socket.close(); } catch { /* noop */ }
  }
}

function hasHeader(map: Record<string, string>, name: string): boolean {
  const lower = name.toLowerCase();
  return Object.keys(map).some((k) => k.toLowerCase() === lower);
}

/**
 * Parse a raw HTTP/1.1 response (status line + headers + body) into a
 * structured object. Assumes Content-Length-delimited body; the ERP doesn't
 * use chunked encoding for these endpoints.
 */
function parseHttpResponse(buf: Uint8Array): SocketFetchResponse {
  // Find the end of the headers. Most servers use \r\n\r\n but some send
  // \n\n (esp. through proxies). Support both.
  let sep = findCRLFCRLF(buf);
  let sepLen = 4;
  if (sep < 0) {
    sep = findLFLF(buf);
    sepLen = 2;
  }
  if (sep < 0) {
    const preview = new TextDecoder("ascii").decode(buf.subarray(0, Math.min(buf.byteLength, 200)));
    throw new Error(`socketFetch: malformed response (no header terminator). got ${buf.byteLength} bytes; preview=${JSON.stringify(preview)}`);
  }

  const headerText = new TextDecoder("ascii").decode(buf.subarray(0, sep));
  const lines = headerText.split("\r\n");
  const statusLine = lines.shift() ?? "";
  const m = /^HTTP\/[\d.]+\s+(\d{3})/.exec(statusLine);
  const status = m ? Number.parseInt(m[1], 10) : 0;

  const headers: Record<string, string> = {};
  for (const line of lines) {
    const idx = line.indexOf(":");
    if (idx <= 0) continue;
    const k = line.slice(0, idx).trim().toLowerCase();
    const v = line.slice(idx + 1).trim();
    headers[k] = headers[k] ? `${headers[k]}, ${v}` : v;
  }

  const bodyBytes = buf.subarray(sep + sepLen);
  // Best-effort decode. ERP returns ISO-8859-1 sometimes; try UTF-8 first.
  let body = "";
  try {
    body = new TextDecoder("utf-8").decode(bodyBytes);
  } catch {
    body = new TextDecoder("iso-8859-1").decode(bodyBytes);
  }

  return {
    status,
    headers,
    body,
    json<T = unknown>(): T {
      return JSON.parse(body) as T;
    },
  };
}

function findCRLFCRLF(buf: Uint8Array): number {
  for (let i = 0; i + 3 < buf.byteLength; i++) {
    if (buf[i] === 13 && buf[i + 1] === 10 && buf[i + 2] === 13 && buf[i + 3] === 10) return i;
  }
  return -1;
}

function findLFLF(buf: Uint8Array): number {
  for (let i = 0; i + 1 < buf.byteLength; i++) {
    if (buf[i] === 10 && buf[i + 1] === 10) return i;
  }
  return -1;
}
