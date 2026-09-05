<?php
namespace ListasEstudantes\Woo;

use ListasEstudantes\Domain\BrindeRules;

if (!defined('ABSPATH')) exit;

/**
 * Validação de brindes no checkout: remove o brinde (restaurando o preço)
 * se o carrinho não atingir o mínimo.
 */
final class CheckoutGuard {

    public function register() {
        add_action('woocommerce_checkout_process', array($this, 'validate'));
    }

    public function validate() {
        if (!WC()->cart) {
            return;
        }

        // Calcular total do carrinho sem brindes
        $cart_total_sem_brindes = BrindeRules::cartTotalWithoutBrindes(WC()->cart);
        $has_brindes = BrindeRules::cartHasBrinde();

        // Se tem brindes mas não atingiu o mínimo, remover brindes e aplicar preço normal
        if ($has_brindes && $cart_total_sem_brindes < LISTAS_BRINDES_VALOR_MINIMO) {
            foreach (WC()->cart->get_cart() as $cart_item_key => $cart_item) {
                if (isset($cart_item['is_brinde']) && $cart_item['is_brinde'] === true) {
                    // Remover flag de brinde e aplicar preço normal
                    unset(WC()->cart->cart_contents[$cart_item_key]['is_brinde']);
                    $original_price = isset($cart_item['brinde_original_price']) ? $cart_item['brinde_original_price'] : $cart_item['data']->get_regular_price();
                    WC()->cart->cart_contents[$cart_item_key]['data']->set_price($original_price);
                }
            }
            WC()->cart->calculate_totals();

            wc_add_notice(
                sprintf(
                    'Você precisa ter pelo menos R$ %s em produtos para receber brindes grátis. Os brindes foram adicionados com preço normal.',
                    number_format(LISTAS_BRINDES_VALOR_MINIMO, 2, ',', '.')
                ),
                'notice'
            );
        }
    }
}
