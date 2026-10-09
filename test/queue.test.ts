import { describe, expect, it } from "vitest";
import { isPostponed } from "../src/core";

describe("isPostponed", () => {
  const now = Date.parse("2026-10-01T13:00:00.000Z");
  const em = (min: number) => new Date(now + min * 60_000).toISOString();

  it("linha sem next_retry_at não está adiada", () => {
    expect(isPostponed(null, now)).toBe(false);
    expect(isPostponed(undefined, now)).toBe(false);
  });

  it("carência normal do dreno (até 15 min) não conta como adiamento", () => {
    expect(isPostponed(em(0), now)).toBe(false);
    expect(isPostponed(em(15), now)).toBe(false);
    expect(isPostponed(em(16), now)).toBe(false);
  });

  it("adiada de propósito para bem depois da carência", () => {
    expect(isPostponed(em(30), now)).toBe(true);
    expect(isPostponed(em(120), now)).toBe(true);
  });

  it("data inválida não bloqueia a linha", () => {
    expect(isPostponed("não é data", now)).toBe(false);
  });
});
