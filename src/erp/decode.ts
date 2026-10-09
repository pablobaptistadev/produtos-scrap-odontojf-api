/**
 * The ERP answers in ISO-8859-1 (Latin-1), not UTF-8. The decoder has to be
 * `fatal`: a plain TextDecoder("utf-8") never throws, it silently turns every
 * accented byte into U+FFFD, so the Latin-1 fallback never ran and names
 * reached the store as "PIN�A GOIVA 6B" instead of "PINÇA GOIVA 6B".
 */
export function decodeBody(bytes: Uint8Array): string {
  try {
    return new TextDecoder("utf-8", { fatal: true, ignoreBOM: false }).decode(bytes);
  } catch {
    // WHATWG maps latin1/iso-8859-1 to windows-1252, a superset (adds €, “ ” …)
    return new TextDecoder("windows-1252").decode(bytes);
  }
}
