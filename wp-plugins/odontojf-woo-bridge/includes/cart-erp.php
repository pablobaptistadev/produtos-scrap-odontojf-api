<?php
/**
 * OdontoJF Woo Bridge — preço/estoque AO VIVO do ERP no carrinho.
 *
 * Toda vez que um produto é adicionado ao carrinho, consultamos o ERP pelo
 * código (= _sku do produto/variação) para validar o estoque e usar o preço
 * atual do ERP. O token do ERP é renovado automaticamente (erp-client.php).
 *
 * Liga/desliga em Configurações (OJF_ERP_CART_LIVE).
 */

if (!defined('ABSPATH')) exit;

/** Código ERP de um item de carrinho/produto. Usa o meta _ojf_erp_code (código
 *  ERP real, mesmo quando o _sku foi sufixado p/ evitar colisão); cai no _sku. */
function ojf_cart_erp_code($product) {
    if (!$product instanceof WC_Product) return '';
    $code = $product->get_meta('_ojf_erp_code');
    if ($code) return trim((string) $code);
    $sku = $product->get_sku();
    return $sku ? trim($sku) : '';
}

/* ── 1) Validação de estoque AO ADICIONAR (consulta fresca no ERP) ────────── */
add_filter('woocommerce_add_to_cart_validation', function ($passed, $product_id, $qty, $variation_id = 0, $variations = [], $cart_item_data = []) {
    if (!OJF_ERP_CART_LIVE) return $passed;
    $id = $variation_id ? $variation_id : $product_id;
    $product = wc_get_product($id);
    $code = ojf_cart_erp_code($product);
    if ($code === '') return $passed; // sem código ERP → não bloqueia

    // soma com o que já está no carrinho do mesmo produto
    $already = 0;
    if (WC()->cart) {
        foreach (WC()->cart->get_cart() as $it) {
            if (ojf_cart_erp_code($it['data']) === $code) $already += (int) $it['quantity'];
        }
    }
    $woo_price = $product ? $product->get_price() : null; // preço do catálogo ANTES da consulta
    $ps = ojf_erp_price_stock($code, true); // FRESH no momento do add
    $approved = !($ps['stock'] !== null && $ps['stock'] < ($already + (int) $qty));

    // LOG no produto (custom field JSON): consulta (catálogo Woo × ERP ao vivo) +
    // detecção de mudança de preço.
    if (function_exists('ojf_log_cart_consult')) {
        ojf_log_cart_consult($id, $code, $woo_price, $ps['price'], $ps['stock'], $approved, (int) $qty);
    }

    if (!$approved) {
        wc_add_notice(sprintf(
            'Estoque insuficiente para "%s". Disponível no momento: %d.',
            $product ? $product->get_name() : $code, max(0, (int) $ps['stock'])
        ), 'error');
        return false;
    }
    return $passed;
}, 10, 6);

/* ── 2) Preço AO VIVO no cálculo do carrinho ──────────────────────────────── */
add_action('woocommerce_before_calculate_totals', function ($cart) {
    if (!OJF_ERP_CART_LIVE) return;
    if (is_admin() && !defined('DOING_AJAX')) return;
    if (!$cart || empty($cart->get_cart())) return;

    foreach ($cart->get_cart() as $item) {
        $product = $item['data'];
        $code = ojf_cart_erp_code($product);
        if ($code === '') continue;
        $ps = ojf_erp_price_stock($code); // cache curto (60s)
        if ($ps['price'] !== null && $ps['price'] > 0) {
            $product->set_price($ps['price']);
        }
    }
}, 20, 1);

/* ── 3) Força consulta fresca no momento exato do add-to-cart ─────────────── */
add_action('woocommerce_add_to_cart', function ($cart_item_key, $product_id, $qty, $variation_id) {
    if (!OJF_ERP_CART_LIVE) return;
    $id = $variation_id ? $variation_id : $product_id;
    $product = wc_get_product($id);
    $code = ojf_cart_erp_code($product);
    if ($code !== '') ojf_erp_price_stock($code, true); // refresca o cache de 60s
}, 10, 4);
