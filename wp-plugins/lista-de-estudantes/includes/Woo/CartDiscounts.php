<?php
namespace ListasEstudantes\Woo;

use ListasEstudantes\Domain\BrindeRules;

if (!defined('ABSPATH')) exit;

/**
 * Integração com o carrinho: anexa o desconto da lista ao item, aplica os
 * preços (desconto/brinde) e exibe badges/preço riscado no carrinho.
 */
final class CartDiscounts {

    public function register() {
        add_filter('woocommerce_add_cart_item_data', array($this, 'addCartItemData'), 10, 3);
        add_action('woocommerce_before_calculate_totals', array($this, 'applyDiscounts'), 10, 1);
        add_filter('woocommerce_cart_item_name', array($this, 'addDiscountBadge'), 10, 3);
        add_filter('woocommerce_cart_item_price', array($this, 'showCrossedPrice'), 10, 3);
    }

    public function addCartItemData($cart_item_data, $product_id, $variation_id) {
        $categories = wp_get_post_terms($product_id, 'product_cat', array('fields' => 'ids'));

        foreach ($categories as $cat_id) {
            $parent = wp_get_term_taxonomy_parent_id($cat_id, 'product_cat');
            if ($parent == LISTAS_PARENT_CAT_ID) {
                $lista_post = get_posts(array(
                    'post_type' => 'lista_estudante',
                    'meta_key' => '_listas_categoria_id',
                    'meta_value' => $cat_id,
                    'posts_per_page' => 1
                ));

                if (!empty($lista_post)) {
                    $lista_id = $lista_post[0]->ID;
                    $cupom_ativo = get_post_meta($lista_id, '_listas_cupom_ativo', true);

                    if ($cupom_ativo === '1') {
                        $cupom_tipo = get_post_meta($lista_id, '_listas_cupom_tipo', true);
                        $cupom_valor = floatval(get_post_meta($lista_id, '_listas_cupom_valor', true));
                        $cupom_minimo = floatval(get_post_meta($lista_id, '_listas_cupom_minimo', true));
                        $escola = get_post_meta($lista_id, '_listas_escola', true);
                        $turma = get_post_meta($lista_id, '_listas_turma', true);

                        if ($cupom_valor > 0) {
                            $cart_item_data['lista_desconto'] = array(
                                'tipo' => $cupom_tipo,
                                'valor' => $cupom_valor,
                                'minimo' => $cupom_minimo,
                                'escola' => $escola,
                                'turma' => $turma,
                                'lista_id' => $lista_id
                            );
                        }
                    }
                }
                break;
            }
        }

        return $cart_item_data;
    }

    public function applyDiscounts($cart) {
        if (is_admin() && !defined('DOING_AJAX')) return;
        if (did_action('woocommerce_before_calculate_totals') >= 2) return;

        // Calcular total do carrinho SEM brindes para validação
        $cart_total_sem_brindes = BrindeRules::cartTotalWithoutBrindes($cart);

        foreach ($cart->get_cart() as $cart_item_key => $cart_item) {
            // Verificar brindes e aplicar desconto apenas se o total for >= mínimo
            if (isset($cart_item['is_brinde']) && $cart_item['is_brinde'] === true) {
                // Se o total sem brindes for >= mínimo, aplicar desconto de 100%
                if ($cart_total_sem_brindes >= LISTAS_BRINDES_VALOR_MINIMO) {
                    $cart_item['data']->set_price(0);
                } else {
                    // Se não atingiu o mínimo, remover flag de brinde e aplicar preço normal
                    unset($cart_item['is_brinde']);
                    $cart_item['data']->set_price(isset($cart_item['brinde_original_price']) ? $cart_item['brinde_original_price'] : $cart_item['data']->get_regular_price());
                    // Atualizar item no carrinho
                    $cart->cart_contents[$cart_item_key] = $cart_item;
                }
                continue;
            }

            // Aplicar desconto da lista se existir
            if (isset($cart_item['lista_desconto'])) {
                $desconto_info = $cart_item['lista_desconto'];

                if ($desconto_info['minimo'] > 0 && $cart_total_sem_brindes < $desconto_info['minimo']) {
                    continue;
                }

                $preco_original = $cart_item['data']->get_price();
                $novo_preco = $preco_original;

                if ($desconto_info['tipo'] === 'percent') {
                    $novo_preco = $preco_original * (1 - ($desconto_info['valor'] / 100));
                } else {
                    $novo_preco = max(0, $preco_original - $desconto_info['valor']);
                }

                $cart_item['data']->set_price($novo_preco);
            }
        }
    }

    public function addDiscountBadge($product_name, $cart_item, $cart_item_key) {
        // Badge para brindes - apenas se o total do carrinho (sem brindes) for >= mínimo
        if (isset($cart_item['is_brinde']) && $cart_item['is_brinde'] === true) {
            $cart_total_sem_brindes = BrindeRules::cartTotalWithoutBrindes(WC()->cart);

            // Só mostrar badge se atingiu o mínimo
            if ($cart_total_sem_brindes >= LISTAS_BRINDES_VALOR_MINIMO) {
                $badge = '<span style="display: inline-block; background: linear-gradient(135deg, #ff3b30, #ff6b6b); color: white; padding: 4px 10px; border-radius: 6px; font-size: 11px; font-weight: 700; margin-left: 8px; vertical-align: middle; letter-spacing: 0.5px;">BRINDE GRÁTIS</span>';
                $product_name .= $badge;
            }
            return $product_name;
        }

        // Badge para descontos da lista
        if (isset($cart_item['lista_desconto'])) {
            $desconto_info = $cart_item['lista_desconto'];
            $produto = $cart_item['data'];

            $preco_atual = $produto->get_price();
            $preco_original = wc_get_product($cart_item['product_id'])->get_regular_price();

            if ($preco_original > 0 && $preco_atual < $preco_original) {
                $percentual = round((($preco_original - $preco_atual) / $preco_original) * 100);

                $badge = sprintf(
                    '<span style="display: inline-block; background: linear-gradient(135deg, #34c759, #30d158); color: white; padding: 4px 10px; border-radius: 6px; font-size: 11px; font-weight: 700; margin-left: 8px; vertical-align: middle; letter-spacing: 0.5px;">%d%% OFF</span>',
                    $percentual
                );

                $lista_info = sprintf(
                    '<br><small style="color: #666; font-size: 12px;">Lista: %s - %s</small>',
                    esc_html($desconto_info['escola']),
                    esc_html($desconto_info['turma'])
                );

                $product_name .= $badge . $lista_info;
            }
        }

        return $product_name;
    }

    public function showCrossedPrice($price, $cart_item, $cart_item_key) {
        // Mostrar preço riscado para brindes - apenas se o total do carrinho (sem brindes) for >= mínimo
        if (isset($cart_item['is_brinde']) && $cart_item['is_brinde'] === true) {
            $cart_total_sem_brindes = BrindeRules::cartTotalWithoutBrindes(WC()->cart);

            // Só mostrar "Grátis" se atingiu o mínimo
            if ($cart_total_sem_brindes >= LISTAS_BRINDES_VALOR_MINIMO) {
                $preco_original = isset($cart_item['brinde_original_price']) ? $cart_item['brinde_original_price'] : $cart_item['data']->get_regular_price();
                $price = sprintf(
                    '<span style="text-decoration: line-through; color: #999; font-size: 13px;">%s</span><br><span style="color: #34c759; font-weight: 700; font-size: 16px;">Grátis</span>',
                    wc_price($preco_original),
                    wc_price(0)
                );
            } else {
                // Se não atingiu o mínimo, mostrar preço normal
                $preco_atual = $cart_item['data']->get_price();
                $price = wc_price($preco_atual);
            }
            return $price;
        }

        // Mostrar preço riscado para descontos da lista
        if (isset($cart_item['lista_desconto'])) {
            $produto_original = wc_get_product($cart_item['product_id']);
            $preco_original = $produto_original->get_regular_price();
            $preco_atual = $cart_item['data']->get_price();

            if ($preco_original > 0 && $preco_atual < $preco_original) {
                $price = sprintf(
                    '<span style="text-decoration: line-through; color: #999; font-size: 13px;">%s</span><br><span style="color: #34c759; font-weight: 700; font-size: 16px;">%s</span>',
                    wc_price($preco_original),
                    wc_price($preco_atual)
                );
            }
        }

        return $price;
    }
}
