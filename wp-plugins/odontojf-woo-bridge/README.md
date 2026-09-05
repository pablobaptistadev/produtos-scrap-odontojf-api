# OdontoJF Woo Bridge — v1.0.56

Plugin WordPress que recebe os produtos do Worker OdontoJF numa **fila própria** (com
timing/retry), cria/atualiza no WooCommerce com **atributos manuais** (não globais),
e serve as imagens via **R2** (fila de imagens: download → WebP → upload SigV4 → CDN).

## Origem do código (não reinventado)

A fila de API, a fila de imagens e o upload R2 (SigV4) são **portados verbatim** do
fluxo validado `fullbai-codes/apis-products`, só com os seams trocados:

| Arquivo | Origem | Mudança |
|---|---|---|
| `includes/api-queue.php` | `fullbai-api-queue.php` (verbatim) | auth `sellers_meta` → segredo único; `$seller` fixo; `+queue_id` no /queue-status |
| `includes/image-handler.php` | `fullbai-cdn-image-handler.php` (verbatim) | R2 → conta nova (ce2d7a097…) |
| `includes/image-dashboard.php` | `fullbai-cdn-image-queue-dashboard.php` (verbatim) | só rename de prefixo |
| `includes/product-handler.php` | mecanismo do `api endpoints fullbai` | `_sku`=ERP (upsert por SKU), `meta_data[]`, categorias por nome; mantém atributo manual `set_id(0)` e variações 1-a-1 |
| `includes/auth.php`, `config.php` | novos | segredo único + config mínima |

## Arquitetura

```
Worker (Cloudflare)                     WordPress (odonto.wpatomic.com.br)
  push stage ──POST /wp-json/odontojf/v1/create-product──►  interceptor
                Authorization: Bearer <segredo>             valida + enfileira (ojf_api_queue)
                                          ◄── {queued, queue_id} (~ms)
                                                            worker (shutdown/cron):
                                                              CAS claim → cria produto (atributo
                                                              manual, _sku=ERP) → fila de imagens
                                                              → duration_ms
  cron reconcile ──GET /queue-status?queue_id=──►           {status, duration_ms, product_id}
```

Observabilidade dos **dois lados**: o Worker mede o tempo de envio (sync_queue) e o
plugin mede o tempo real de cadastro/update (`duration_ms`), visível nos dashboards.

## Instalação

1. Suba a pasta `odontojf-woo-bridge/` para `wp-content/plugins/` (ou instale o zip).
2. Ative o plugin no admin do WordPress. (Cria as tabelas `*_ojf_api_queue` e `*_ojf_cdn_image_queue`.)
3. **wp-config.php** — defina os segredos (NÃO ficam no código):
   ```php
   define('OJF_R2_SECRET_KEY', '<secret access key do R2 da conta nova>');
   // opcional: sobrescrever bucket/cdn se diferentes do default
   define('OJF_R2_BUCKET',    'odontojf-woo-images');
   define('OJF_CDN_BASE_URL', 'https://cdn-woo.odontoapi.wpatomic.com.br');
   ```
4. Admin → **OdontoJF Bridge → Configurações**: cole o **Bearer secret** (o MESMO que
   o Worker usa em `WOO_PLUGIN_API_KEY`).
5. No R2 (conta `ce2d7a097…`): crie o bucket `odontojf-woo-images` e vincule o domínio
   CDN (`cdn-woo.odontoapi…`). Sem o domínio CDN, os produtos publicam mas as imagens dão 404.

## Endpoints (namespace `odontojf/v1`)

| Método | Rota | Descrição |
|---|---|---|
| POST | `/create-product` | enfileira create/upsert (por `_sku`) |
| POST | `/update-product` | idem (upsert) |
| POST | `/delete-product` | enfileira delete por `_sku` |
| GET  | `/queue-status?queue_id=N` | status + `duration_ms` + `product_id` |
| POST | `/queue-retry-failed` | recoloca os `failed` na fila |
| GET  | `/health` | versão do plugin |

Auth: `Authorization: Bearer <segredo>`.

## Payload (create/update)

```json
{
  "sku": "230", "type": "simple|variable", "name": "...", "status": "publish",
  "regular_price": "17.70", "sale_price": "", "stock_quantity": 26, "weight": "0.001",
  "dimensions": {"length":"0","width":"0","height":"0"},
  "categories": [{"name": "ODONTOLOGICO"}],
  "attributes": [{"name":"Marca","options":["SS WHITE"],"visible":true,"variation":false}],
  "images": [{"src":"https://media.odontoapi.../0-a.jpg"}],
  "meta_data": [{"key":"_odontojf_sku","value":"230"}],
  "variations": [{
    "sku": "411", "regular_price": "50.00",
    "name": "Fórceps Adulto N°150",                       // título próprio do filho (>= 1.0.36)
    "description": "<p>…</p>",                            // descrição própria do filho (>= 1.0.36)
    "image": {"src": "…/0-a.jpg"},                        // compat: 1 imagem
    "images": [{"src": "…/0-a.jpg"}, {"src": "…/1-b.jpg"}], // galeria (>= 1.0.36)
    "attributes": [{"name": "Variação", "option": "N°150"}]
  }],
  "idem_key": "<sha1>"
}
```

- **Atributos manuais**: `attributes[]` sem `id` → o plugin usa `WC_Product_Attribute->set_id(0)`.
- **`_sku` = código ERP**: upsert por `_sku` (não duplica).
- **idem_key**: re-POST do mesmo payload é no-op (retorna o mesmo `queue_id`).

## Dashboards (wp-admin → OdontoJF Bridge)

- **API Queue**: contagem por status, tempo médio (`duration_ms`), 30 últimos, "Retry failed".
- **Image Queue**: status, formato/dim/tamanho WebP, link CDN, "Retry failed".

## Variações fiéis à origem (1.0.36)

Na origem cada tamanho/modelo é um **produto próprio** (página, título, descrição e
galeria) agrupado por um pai sem código. O WooCommerce guarda isso como variação, que
nativamente só tem **uma** imagem e nenhum título/descrição próprios. A 1.0.36 fecha essa
lacuna sem sair do CRUD nativo (`WC_Product_Variation`), então **re-push atualiza no
lugar — nada é apagado nem recadastrado, e nenhum SKU muda**.

| campo do payload | onde vai parar |
|---|---|
| `variations[].name` | `set_name()` — título da variação no admin e no carrinho |
| `variations[].description` | `set_description()` — descrição exibida ao selecionar |
| `variations[].images[]` | 1ª → `set_image_id()`; demais → meta `_odontojf_variation_gallery` (CSV de attachment IDs) |

`includes/variation-gallery.php` faz as três pontas:

- **CommerceKit (é quem desenha a galeria nesta loja)** — o markup nativo do WooCommerce
  fica estacionado dentro de `<template class="wc-product-gallery-default-template">`; a
  galeria visível é o swiper `#commercegurus-pdp-gallery`. O módulo *Attributes Gallery*
  do CommerceKit já está ligado e **funciona com atributos manuais** — na loja
  `cgkit_attr_names` é `["attribute_variacao"]`. Ele lê o post meta
  `commercekit_image_gallery` do **produto pai**, um array
  `[ sanitize_title(<valor da variação>) => "id1,id2,id3" ]`. O Bridge espelha a galeria
  nesse meta, então a troca ao escolher a variação sai com swiper, thumbs e lightbox
  nativos, **sem JS nosso e sem acoplar ao tema**. `default_gallery`, `global_gallery` e
  galerias curadas à mão fora do eixo de variação são preservadas.
- **Front (fallback)** — sem o CommerceKit, o filtro `woocommerce_available_variation`
  injeta `ojf_gallery_html` (gerado por `wc_get_gallery_image_html()`, a mesma função do
  core) e um JS inline troca a galeria em `found_variation`, restaurando a original em
  `reset_data`. Nesta loja ele se auto-desliga: `.woocommerce-product-gallery` não existe
  no DOM (está dentro do `<template>`).
- **Admin** — campo "Galeria da variação" na aba Variações (media modal do WP), salvo em
  `woocommerce_save_product_variation`. A curadoria manual vale **até o próximo sync do
  SKU**: a origem é a fonte da verdade e o worker sobrescreve o meta.

> ⚠️ **PILAR B.** `ojf_collect_product_attachment_ids()` define o que "está em uso"; todo
> anexo com `_ojf_r2_object_key` fora dessa lista é deletado no update seguinte, e o hook
> `delete_attachment` leva o objeto no R2 junto. Por isso a 1.0.36 também estendeu essa
> função com `ojf_get_variation_gallery_ids()`. Qualquer meta novo que aponte para anexos
> precisa entrar lá **antes** de ser gravado.

O meta próprio (`_odontojf_variation_gallery`) continua sendo o registro canônico: ele
funciona sem o CommerceKit, alimenta o campo do admin e é o que o PILAR B enxerga.
`commercekit_image_gallery` é só o espelho de renderização.

## Guarda anti-duplicação (1.0.56)

O `_sku` do pai variável é **sintético**: `OD-<código da 1ª variação>`. A origem
reordena e remove tamanhos, então esse código **muda sozinho** — e aí o `create` não
achava o pai pelo SKU, o Woo criava um **segundo produto** e o `save()` de cada
variação reescrevia o `post_parent` dela. O original ficava **vazio e publicado**.
Aconteceu de verdade: `#773421` (`OD-19722`) → `#791839` (`OD-20591`), 12 variações.

Antes de criar, `ojf_adopt_existing_product()` procura o produto que o payload **já é**:

| ordem | âncora | como |
|---|---|---|
| 1 | slug da origem | `post_name` = slug, `post_status=publish`, `_seller=odontojf` |
| 2 | dono das variações | `ojf_variation_owner_of_sku()` sobre os códigos do payload |

Achando, **adota**: atualiza o produto existente e re-chaveia o `_sku` nele
(`_ojf_previous_sku` guarda o antigo; a resposta traz `adopted_from_sku`).

Recusa com **409** quando adotar seria destrutivo:

- `variations_span_parents` — as variações do payload estão em mais de um pai publicado;
- `adoption_overlap_too_low` — menos de 50% das variações vivas do candidato estão no
  payload (o PILAR C apagaria as de fora).

E de 1.0.36, ainda de pé: `sku_belongs_to_variation` — recusa criar um **produto
simples** com um SKU que já é de uma variação viva (o `familyProduct` da origem
entrando solto no pipeline).

## Empacotamento

```bash
scripts/build-plugin.sh      # gera odontojf-woo-bridge.zip na raiz do repo
```

O **fonte** em `wp-plugins/odontojf-woo-bridge/` é o histórico; o zip da raiz é sempre
gerado pelo script, nunca editado à mão. Deploy pelo WP Atomic Deploy:
`POST /wp-json/wpatomic-deploy/v1/upload?activate=true` (multipart, campo `file`, header
`X-Deploy-Token`). Rollback: `POST /restore` com `{"slug":"odontojf-woo-bridge"}`.

## Versionamento

`OJF_BRIDGE_VERSION` no header e nas respostas da API. A cada alteração: bump a versão
e adicione uma linha no CHANGELOG (topo do `odontojf-woo-bridge.php`).
