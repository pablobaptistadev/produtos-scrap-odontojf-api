<?php
namespace ListasEstudantes\Domain;

if (!defined('ABSPATH')) exit;

/**
 * Regras de desconto de produto (percentual exibido nas tags/badges).
 */
final class DiscountRules {

    /**
     * @param \WC_Product|int|mixed $product
     * @return int percentual de desconto (0 se não houver)
     */
    public static function productDiscountPercent($product) {
        if (!$product) {
            return 0;
        }

        if (!($product instanceof \WC_Product)) {
            $product = wc_get_product($product);
        }

        if (!$product) {
            return 0;
        }

        $regular_price = floatval($product->get_regular_price());
        $current_price = floatval($product->get_price());

        if ($regular_price > 0 && $current_price > 0 && $current_price < $regular_price) {
            return max(0, round((($regular_price - $current_price) / $regular_price) * 100));
        }

        return 0;
    }
}
