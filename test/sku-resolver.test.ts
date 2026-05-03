import { describe, it, expect } from "vitest";
import { resolveSku } from "../src/scraper/sku-resolver";

describe("resolveSku", () => {
  it("prefers HTML SKU when present", () => {
    expect(resolveSku("any-slug", "ABC-123")).toEqual({ sku: "ABC-123", provisional: false, source: "html" });
  });

  it("extracts code from end of slug", () => {
    expect(resolveSku("dispensador-3m-solventum-espe-express-3m-solventum-hb004245674", null)).toEqual({
      sku: "hb004245674",
      provisional: true,
      source: "slug-tail",
    });
  });

  it("falls back to slug when no code is found", () => {
    const result = resolveSku("dispensador-pistola-universal-yller", null);
    expect(result.sku).toBe("dispensador-pistola-universal-yller");
    expect(result.provisional).toBe(true);
    expect(result.source).toBe("slug-fallback");
  });

  it("does not match short alphanumeric tails as codes", () => {
    const result = resolveSku("dispensador-yller", null);
    expect(result.source).toBe("slug-fallback");
  });
});
