# OdontoJF Woo Bridge — v1.0.60

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

## Identidade do produto = slug da origem (1.0.56)

O `_sku` do pai variável é **sintético**: `OD-<código da 1ª variação>`. A origem
reordena e remove tamanhos, então esse código **muda sozinho** — e aí o `create` não
achava o pai pelo SKU, o Woo criava um **segundo produto** e o `save()` de cada
variação reescrevia o `post_parent` dela (`ojf_sync_variations` casa a variação por
SKU e chama `set_parent_id()`). O original ficava **publicado e vazio**. Aconteceu de
verdade: `#773421` (`OD-19722`) → `#791839` (`OD-20591`), 12 variações.

O slug é a chave estável — é a URL da origem, e é a URL que o cliente e o Google têm.
`ojf_resolve_target_product()` decide o alvo assim:

| caso | slug acha | sku acha | resultado |
|---|---|---|---|
| 1 | — / mesmo | sim | update normal (`matched_by: sku`) |
| 2 | **#A** | **#B** | **duplicata**: canônico = #A; #B vira gêmeo |
| 3 | #A | — | adota #A e re-chaveia o `_sku` nele |
| 4 | — | — | dono atual das variações; senão cria |

A busca por slug exige `post_name` = slug, publicado e `_seller = odontojf` — produto
cadastrado à mão nunca é adotado. Ao adotar, o `_sku` antigo fica em
`_ojf_previous_sku` e a resposta traz `adopted_from_sku` e `matched_by`.

### Como a duplicata é absorvida (caso 2)

1. o canônico (do slug) é atualizado e recebe o `_sku` do payload —
   `ojf_release_sku_from_product()` solta o código do gêmeo **zerando o `_sku`**, sem
   apagar nada (`ojf_free_sku_global()` faz `wp_delete_post(.., true)`, e isso levaria
   os anexos e os objetos no R2 junto pelo hook `delete_attachment`);
2. `ojf_sync_variations()` casa cada variação por SKU e chama `set_parent_id()` — as
   variações **voltam sozinhas** para o canônico;
3. só então `ojf_absorb_duplicate()` roda: se o gêmeo ficou sem nenhuma variação, vira
   **rascunho** com `_ojf_duplicate_of` e `_ojf_duplicate_at`. Se sobrou alguma, ele
   fica publicado e a resposta diz isso em `duplicate_absorbed`.

**Nunca apaga.** Sai do ar, é reversível, e sobra rastro.

### Recusas (409)

| código | quando |
|---|---|
| `duplicate_products` | o gêmeo não é nosso, ou tem variação que não está no payload |
| `variations_span_parents` | as variações do payload vivem sob mais de um pai publicado |
| `adoption_overlap_too_low` | menos de 50% das variações vivas do candidato estão no payload (o PILAR C apagaria o resto) |
| `sku_belongs_to_variation` | (1.0.36) criar produto **simples** com SKU que já é de uma variação viva |

### Código de variação compartilhado entre produtos diferentes

Outro caminho de duplicação, e este o slug não resolve: a origem **reaproveita o mesmo
código de item** em kits e promoções. Medido na loja — `resina-filtek-z350-xt` e
`resina-filtek-z350-xt-4g-promo-sof-lex` dividem **10 códigos**;
`dente-biotone-ipn-anterior-inferior` e `dente-biotone-anterior-inferior`, 4. Como o
`_sku` é único no Woo, quem empurrasse por último **levava a variação do outro** — e os
dois produtos se revezavam para sempre.

`ojf_sync_variations()` agora resolve cada variação nesta ordem:

1. variação **do próprio pai** com aquele código (`ojf_find_own_variation()`, casa por
   `_ojf_erp_code` — sobrevive ao sufixo, então não recria a variação a cada push);
2. código preso em algo **vivo de outro produto** (`ojf_sku_taken_by_other()`) → usa
   `_sku` sufixado `<código>-p<pai>` em vez de tomar. O código real continua em
   `_ojf_erp_code`, que é o que o carrinho e os shortcodes leem;
3. senão, reusa a variação solta que já carrega aquele `_sku`.

`ojf_free_orphan_sku()` substitui `ojf_free_sku_global()` nesse caminho: solta só órfão
(variação sem pai publicado, produto em rascunho), nunca mexe em variação de pai
publicado nem apaga produto publicado.

Durante a absorção de uma duplicata o gêmeo é passado em `$absorb_from`, para que as
variações dele sejam **puxadas de volta** em vez de sufixadas.

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
