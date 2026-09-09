<?php
namespace ListasEstudantes\Domain;

if (!defined('ABSPATH')) exit;

/**
 * Regras de brinde: mínimo de LISTAS_BRINDES_VALOR_MINIMO em produtos
 * (sem contar brindes), máximo de 1 brinde por carrinho, e o produto
 * precisa pertencer à categoria LISTAS_BRINDES_CAT_ID.
 */
final class BrindeRules {

    /**
     * Total do carrinho SEM brindes (usa preço regular, como o legado).
     *
     * @param \WC_Cart|null $cart
     * @return float
     */
    public static function cartTotalWithoutBrindes($cart) {
        $total = 0;

        if (!$cart) {
            return $total;
        }

        foreach ($cart->get_cart() as $item) {
            if (!isset($item['is_brinde']) || !$item['is_brinde']) {
                $product = $item['data'];
                $price = $product->get_regular_price() ?: $product->get_price();
                $quantity = $item['quantity'];
                $total += floatval($price) * $quantity;
            }
        }

        return $total;
    }

    /**
     * Já existe um brinde no carrinho atual?
     *
     * @return bool
     */
    public static function cartHasBrinde() {
        if (WC()->cart && !WC()->cart->is_empty()) {
            foreach (WC()->cart->get_cart() as $cart_item) {
                if (isset($cart_item['is_brinde']) && $cart_item['is_brinde'] === true) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * O produto pertence à categoria de brindes?
     *
     * @param int $product_id
     * @return bool
     */
    public static function isBrindeProduct($product_id) {
        $product_categories = wp_get_post_terms($product_id, 'product_cat', array('fields' => 'ids'));
        return in_array(LISTAS_BRINDES_CAT_ID, $product_categories);
    }
}
