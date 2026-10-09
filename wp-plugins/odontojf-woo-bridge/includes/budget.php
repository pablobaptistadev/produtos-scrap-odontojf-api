<?php
/**
 * Produto sob orçamento (>= 1.0.77): no grid, "Solicitar orçamento" no lugar de
 * preço/comprar, levando para a página do produto — como a origem faz com os
 * itens needsBudget (as cadeiras OLSEN, por exemplo).
 *
 * "Sob orçamento" = marcado na categoria Orçamento (ojf_budget_cat_id(), que o
 * push mantém a partir do needs_budget da origem).
 *
 * Os cards do grid são itens de listagem do JetEngine com o widget "Adicionar ao
 * carrinho" do Elementor Pro; as listas padrão do Woo (busca, relacionados) usam
 * woocommerce_loop_add_to_cart_link. Os dois são trocados aqui, sem editar
 * template nenhum.
 */

if (!defined('ABSPATH')) exit;

function ojf_product_needs_budget($product) {
    if (!function_exists('ojf_budget_cat_id')) return false;
    $orc = ojf_budget_cat_id();
    if (!$orc) return false;
    if (is_numeric($product)) $product = wc_get_product((int) $product);
    if (!$product instanceof WC_Product) return false;
    $id = $product->is_type('variation') ? $product->get_parent_id() : $product->get_id();
    return has_term($orc, 'product_cat', $id);
}

function ojf_budget_button_html($product, $extra_class = '') {
    $url = get_permalink($product->is_type('variation') ? $product->get_parent_id() : $product->get_id());
    return sprintf(
        '<a href="%s" class="elementor-button elementor-size-sm ojf-orcamento-btn %s" role="button" aria-label="%s">'
        . '<span class="elementor-button-content-wrapper"><span class="elementor-button-text">Solicitar orçamento</span></span></a>',
        esc_url($url),
        esc_attr($extra_class),
        esc_attr(sprintf('Solicitar orçamento de %s', wp_strip_all_tags($product->get_name())))
    );
}

/* grid do JetEngine / Elementor: o widget "Adicionar ao carrinho" vira o botão */
add_filter('elementor/widget/render_content', function ($content, $widget) {
    if (!is_object($widget) || !method_exists($widget, 'get_name')) return $content;
    if ($widget->get_name() !== 'wc-add-to-cart') return $content;
    $product = wc_get_product(get_the_ID());
    if (!$product || !ojf_product_needs_budget($product)) return $content;
    return ojf_budget_button_html($product, 'ojf-orcamento-btn--grid');
}, 20, 2);

/* listas padrão do Woo (busca, relacionados, shortcodes) */
add_filter('woocommerce_loop_add_to_cart_link', function ($html, $product) {
    if (!$product instanceof WC_Product || !ojf_product_needs_budget($product)) return $html;
    return ojf_budget_button_html($product, 'button ojf-orcamento-btn--loop');
}, 20, 2);

/* sem preço no grid: produto sob orçamento não mostra "R$ 0,00" nem faixa */
add_filter('woocommerce_get_price_html', function ($price, $product) {
    if (is_admin() || (is_singular('product') && get_queried_object_id() === $product->get_id())) return $price;
    return ojf_product_needs_budget($product) ? '' : $price;
}, 20, 2);

add_action('wp_head', function () {
    echo '<style id="ojf-orcamento-css">.ojf-orcamento-btn{display:flex;width:100%;justify-content:center;text-align:center}'
       . '.ojf-orcamento-btn .elementor-button-text{white-space:nowrap}</style>';
}, 30);

/*
 * Orçamento não pode ser filha de "Cadeira Odontológica": a página de uma
 * categoria lista também os produtos das filhas, e a bomba de vácuo (sob
 * orçamento) aparecia entre as cadeiras. É uma categoria da loja, não da
 * origem: fica no primeiro nível. Uma vez só (option de controle).
 */
add_action('init', function () {
    if (get_option('ojf_budget_cat_root_v1')) return;
    $orc = function_exists('ojf_budget_cat_id') ? ojf_budget_cat_id() : 0;
    if (!$orc) return;
    $t = get_term($orc, 'product_cat');
    if ($t && !is_wp_error($t) && (int) $t->parent !== 0) {
        $r = wp_update_term($orc, 'product_cat', ['parent' => 0]);
        if (is_wp_error($r)) { error_log('[ojf] Orçamento para o 1º nível falhou: ' . $r->get_error_message()); return; }
    }
    update_option('ojf_budget_cat_root_v1', 1, true);
}, 40);
