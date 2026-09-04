# OdontoJF Woo Bridge — v1.0.0

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
  "variations": [{"sku":"101","regular_price":"50.00","attributes":[{"name":"Variação","option":"A1"}]}],
  "idem_key": "<sha1>"
}
```

- **Atributos manuais**: `attributes[]` sem `id` → o plugin usa `WC_Product_Attribute->set_id(0)`.
- **`_sku` = código ERP**: upsert por `_sku` (não duplica).
- **idem_key**: re-POST do mesmo payload é no-op (retorna o mesmo `queue_id`).

## Dashboards (wp-admin → OdontoJF Bridge)

- **API Queue**: contagem por status, tempo médio (`duration_ms`), 30 últimos, "Retry failed".
- **Image Queue**: status, formato/dim/tamanho WebP, link CDN, "Retry failed".

## Versionamento

`OJF_BRIDGE_VERSION` no header e nas respostas da API. A cada alteração: bump a versão
e adicione uma linha no CHANGELOG (topo do `odontojf-woo-bridge.php`).
