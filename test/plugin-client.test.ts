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

  it("skipPricing leaves price and quantity out, but mirrors the origin's availability", () => {
    const body = buildPluginPayload(forceps as any, "3184", { skipPricing: true });
    const v = body.variations[0];

    // O plugin grava preço/estoque sob isset(): chave ausente = loja mantém o
    // que já tem. É o que sustenta publicar conteúdo com o ERP fora do ar.
    expect(v).not.toHaveProperty("regular_price");
    expect(v).not.toHaveProperty("sale_price");
    expect(v).not.toHaveProperty("stock_quantity");
    expect(body).not.toHaveProperty("regular_price");
    expect(body).not.toHaveProperty("stock_quantity");
    // A disponibilidade segue a origem (esgotado lá = esgotado aqui → Avise-me).
    const forcepsVar = (forceps as any).variations[0];
    if (forcepsVar.stock_status === "instock" || forcepsVar.stock_status === "outofstock") {
      expect(v.stock_status).toBe(forcepsVar.stock_status);
    } else {
      expect(v).not.toHaveProperty("stock_status");
    }

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

  it("skipPricing: produto esgotado na origem vai esgotado; sem informação não manda nada", () => {
    const esgotado = buildPluginPayload({ type: "simple", name: "Y", stock_status: "outofstock", stock_quantity: 0 } as any, "1", { skipPricing: true });
    expect(esgotado.stock_status).toBe("outofstock");
    expect(esgotado).not.toHaveProperty("stock_quantity");
    const semInfo = buildPluginPayload({ type: "simple", name: "Y", stock_status: null } as any, "2", { skipPricing: true });
    expect(semInfo).not.toHaveProperty("stock_status");
    const variavel = buildPluginPayload({
      type: "variable", name: "V",
      variations: [{ sku: "A", stock_status: "outofstock" }, { sku: "B", stock_status: "instock" }, { sku: "C" }],
    } as any, "3", { skipPricing: true });
    expect(variavel.variations.map((x: any) => x.stock_status ?? null)).toEqual(["outofstock", "instock", null]);
  });

  it("does not prefix a parent SKU that is already prefixed", () => {
    expect(buildPluginPayload(forceps as any, "OD-3184").sku).toBe("OD-3184");
  });

  it("leaves a simple product's SKU alone", () => {
    const simple = { type: "simple", name: "Y", regular_price: "10.00" };
    expect(buildPluginPayload(simple as any, "230").sku).toBe("230");
  });
});

import { pluginPayloadHash } from "../src/woo/plugin-client";

describe("pluginPayloadHash — decide se um produto precisa ser reempurrado", () => {
  const env = { WOO_PUSH_PRICING: "store" } as any;
  const base = {
    name: "Fórceps Adulto",
    type: "variable",
    slug: "forceps-odontologico-golgran",
    description: "<p>Aço inox</p>",
    variations: [{ sku: "411", title: "Fórceps Adulto N°150", attributes: [{ name: "variacao", option: "N°150" }] }],
  };

  it("é igual para o mesmo conteúdo, independente da ordem das chaves", async () => {
    const reordenado = { variations: base.variations, description: base.description, slug: base.slug, type: base.type, name: base.name };
    expect(await pluginPayloadHash(env, base, "OD-411")).toBe(await pluginPayloadHash(env, reordenado, "OD-411"));
  });

  it("muda quando o nome muda — é assim que o reparo dos '403: Forbidden' passa", async () => {
    const ruim = { ...base, name: "403: Forbidden" };
    expect(await pluginPayloadHash(env, base, "OD-411")).not.toBe(await pluginPayloadHash(env, ruim, "OD-411"));
  });

  it("muda quando o título de uma variação muda", async () => {
    const outra = { ...base, variations: [{ ...base.variations[0], title: "Fórceps Adulto N°151" }] };
    expect(await pluginPayloadHash(env, base, "OD-411")).not.toBe(await pluginPayloadHash(env, outra, "OD-411"));
  });
});

describe("buildPluginPayload — categorias da origem e orçamento", () => {
  const base = { name: "Consultório Logic Exclusive II", type: "simple", regular_price: null, images: [], variations: [] };

  it("manda as categorias com slug e o needs_budget", () => {
    const body = buildPluginPayload(
      {
        ...base,
        categories: [{ name: "Cadeira Odontológica", slug: "cadeira-odontologica" }],
        needs_budget: true,
      } as any,
      "cadeira1",
      { skipPricing: true },
    );
    expect(body.categories).toEqual([{ name: "Cadeira Odontológica", slug: "cadeira-odontologica" }]);
    expect(body.needs_budget).toBe(true);
  });

  it("sem dado da origem não manda needs_budget (o plugin não mexe em Orçamento)", () => {
    const body = buildPluginPayload({ ...base, needs_budget: null } as any, "x", { skipPricing: true });
    expect("needs_budget" in body).toBe(false);
  });
});
