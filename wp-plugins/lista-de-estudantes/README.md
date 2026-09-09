# Listas de Estudantes

Sistema de listas de compras para estudantes integrado ao WooCommerce
(dentalodontocirurgica.com.br). Cada lista (CPT `lista_estudante`) é
sincronizada com uma categoria de produto filha de `LISTAS_PARENT_CAT_ID`;
a página da categoria vira uma página customizada da lista com seleção
múltipla, variações, produtos similares, desconto e brindes.

## Arquitetura (v2.0.0)

```
lista-de-estudantes.php        Bootstrap: header, constantes, autoloader, hooks de ativação
includes/
  Plugin.php                   Composition root (instancia módulos e registra hooks)
  functions.php                Wrappers globais de compatibilidade (tema/Elementor)
  Setup/                       Ativação (tabelas dbDelta), CPT, menu admin
  Repository/                  SQL das tabelas {prefix}listas_produtos_similares e _ordem
  Domain/                      Regras de negócio puras:
    SkuResolver.php              SKU -> produto pai (aceita variação e prefixo OD-)
    ProductSearchService.php     Busca admin por nome, ID e SKU
    CategorySync.php             Lista <-> categoria WooCommerce
    DiscountRules.php            Percentual de desconto exibido
    BrindeRules.php              Regras de brinde (mínimo, 1 por carrinho, categoria)
  Admin/                       Meta boxes, assets do admin, AJAX admin (nonce + capability)
  Frontend/                    Takeover do template da categoria, assets, AJAX público
  Woo/                         Desconto/brinde no carrinho e validação no checkout
assets/
  admin/{css,js}               Estilos e ListasProdutos (busca, grid, similares, import)
  frontend/{css,js}            Estilos e comportamento da página da lista
templates/
  admin/                       HTML dos 4 meta boxes
  frontend/                    Página da lista + parts (card de produto, brindes)
```

## Regra de SKU (importação em massa e busca)

Um código colado/digitado `X` resolve nesta ordem:
1. SKU exato `X` em produto simples/pai publicado;
2. SKU exato `X` em variação publicada → resolve para o produto **pai**;
3. SKU `OD-X` em produto (clientes colam `20013` para o SKU `OD-20013`);
4. SKU `OD-X` em variação → produto pai.

Implementação: `includes/Domain/SkuResolver.php`. Usada na importação em
massa de produtos e de similares e nas duas buscas do admin.

## Contratos estáveis (não renomear)

- CPT `lista_estudante`; shortcode `lista_categoria_url`
- Actions AJAX `wp_ajax_listas_*` (11 admin com nonce `listas_produtos_nonce`;
  6 frontend com `nopriv` e sem nonce — hardening futuro)
- Meta keys `_listas_*`; option `listas_similares_db_version`
- Tabelas `{prefix}listas_produtos_similares`, `{prefix}listas_produtos_ordem`
- Chaves de item do carrinho: `lista_desconto`, `is_brinde`, `brinde_original_price`

## Deploy

Via WP Atomic Deploy (ver `wpatomic-deploy/AGENT.md` no repo). Bump de versão
no header do plugin E na constante `LISTAS_ESTUDANTES_VERSION`; zip com pasta
interna `lista-de-estudantes/`; upload em `POST /upload?activate=1`.
