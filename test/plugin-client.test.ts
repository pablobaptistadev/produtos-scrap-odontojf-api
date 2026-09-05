import { describe, it, expect } from "vitest";
import { buildPluginPayload } from "../src/woo/plugin-client";

/**
 * Contract with the OdontoJF Woo Bridge (>= 1.0.36). The plugin reads
 * `variations[].name`, `.description` and `.images[]`; anything renamed here
 * silently stops reaching the store, so it is worth pinning.
 */
const forceps = {
  type: "variable",
  name: "Fórceps Adulto",
  images: [{ src: "https://media.example/parent-0.jpg" }],
  variations: [
    {
      sku: "411",
      name: "N°150",
      title: "Fórceps Adulto N°150",
      description: "<p>Indicado para pré-molares superiores.</p>",
      regular_price: "105.63",
      stock_quantity: 3,
      image: { src: "https://media.example/150-a.jpg" },
      images: [
        { src: "https://media.example/150-a.jpg" },
        { src: "https://media.example/150-b.jpg" },
        { src: "https://media.example/150-c.jpg" },
        { src: "https://media.example/150-d.jpg" },
      ],
      attributes: [{ name: "Variação", option: "N°150" }],
      meta_data: [{ key: "_odontojf_variation_title", value: "Fórceps Adulto N°150" }],
    },
  ],
};

describe("buildPluginPayload — faithful variations", () => {
  it("sends the variation's own title, description and full gallery", () => {
    const body = buildPluginPayload(forceps as any, "3184");
    const v = body.variations[0];

    expect(v.name).toBe("Fórceps Adulto N°150");
    expect(v.description).toBe("<p>Indicado para pré-molares superiores.</p>");
    expect(v.images).toHaveLength(4);
    expect(v.images[0]).toEqual({ src: "https://media.example/150-a.jpg" });
    // `image` (singular) stays for bridges older than 1.0.36.
    expect(v.image).toEqual({ src: "https://media.example/150-a.jpg" });
    // The variation keeps the bare ERP code; only the parent is prefixed.
    expect(v.sku).toBe("411");
    expect(body.sku).toBe("OD-3184");
  });

  it("omits the new keys when the origin has nothing to say", () => {
    const bare = {
      type: "variable",
      name: "X",
      variations: [{ sku: "9", name: "A", title: null, description: "", images: [] }],
    };
    const v = buildPluginPayload(bare as any, "9000").variations[0];

    expect(v).not.toHaveProperty("name");
    expect(v).not.toHaveProperty("description");
    expect(v).not.toHaveProperty("images");
  });

  it("skipPricing leaves every price and stock key out", () => {
    const body = buildPluginPayload(forceps as any, "3184", { skipPricing: true });
    const v = body.variations[0];

    // O plugin grava preço/estoque sob isset(): chave ausente = loja mantém o
    // que já tem. É o que sustenta publicar conteúdo com o ERP fora do ar.
    expect(v).not.toHaveProperty("regular_price");
    expect(v).not.toHaveProperty("sale_price");
    expect(v).not.toHaveProperty("stock_quantity");
    expect(v).not.toHaveProperty("stock_status");
    expect(body).not.toHaveProperty("regular_price");
    expect(body).not.toHaveProperty("stock_quantity");

    // e o conteúdo continua indo
    expect(v.name).toBe("Fórceps Adulto N°150");
    expect(v.images).toHaveLength(4);
    expect(v.sku).toBe("411");
  });

  it("skipPricing on a simple product too", () => {
    const simple = { type: "simple", name: "Y", regular_price: "10.00", stock_quantity: 5 };
    const body = buildPluginPayload(simple as any, "230", { skipPricing: true });
    expect(body).not.toHaveProperty("regular_price");
    expect(body).not.toHaveProperty("stock_quantity");
    expect(body.name).toBe("Y");
  });

  it("does not prefix a parent SKU that is already prefixed", () => {
    expect(buildPluginPayload(forceps as any, "OD-3184").sku).toBe("OD-3184");
  });

  it("leaves a simple product's SKU alone", () => {
    const simple = { type: "simple", name: "Y", regular_price: "10.00" };
    expect(buildPluginPayload(simple as any, "230").sku).toBe("230");
  });
});
