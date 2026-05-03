import { describe, it, expect } from "vitest";
import { computeRetryDelaySeconds, deriveHttpStatus, generateApiKey, safeJsonParse } from "../src/core";

describe("core helpers", () => {
  it("backs off exponentially with a 1h cap", () => {
    expect(computeRetryDelaySeconds(1)).toBe(30);
    expect(computeRetryDelaySeconds(2)).toBe(60);
    expect(computeRetryDelaySeconds(3)).toBe(120);
    expect(computeRetryDelaySeconds(7)).toBe(1920);
    expect(computeRetryDelaySeconds(20)).toBe(3600);
  });

  it("derives http status correctly", () => {
    expect(deriveHttpStatus(200, false)).toBe("ok");
    expect(deriveHttpStatus(204, false)).toBe("ok");
    expect(deriveHttpStatus(500, false)).toBe("failed");
    expect(deriveHttpStatus(0, true)).toBe("timed_out");
  });

  it("safeJsonParse returns null on garbage", () => {
    expect(safeJsonParse("not json")).toBe(null);
    expect(safeJsonParse('{"a":1}')).toEqual({ a: 1 });
    expect(safeJsonParse(null)).toBe(null);
  });

  it("generates api keys of consistent length", () => {
    const k1 = generateApiKey();
    const k2 = generateApiKey();
    expect(k1).toHaveLength(64);
    expect(k1).not.toBe(k2);
  });
});
