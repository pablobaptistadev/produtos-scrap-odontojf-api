<?php
namespace ListasEstudantes\Frontend;

if (!defined('ABSPATH')) exit;

/**
 * CSS/JS do template frontend das listas.
 *
 * O enqueue é adicionado ao wp_enqueue_scripts de dentro do takeover do
 * template_include (o hook dispara depois, durante o get_header()).
 */
final class Assets {

    public function enqueue() {
        wp_enqueue_style('montserrat-font', 'https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap');
        wp_enqueue_script('jquery');

        wp_enqueue_style(
            'listas-frontend',
            LISTAS_ESTUDANTES_URL . 'assets/frontend/css/lista.css',
            array(),
            LISTAS_ESTUDANTES_VERSION
        );

        wp_enqueue_script(
            'listas-frontend',
            LISTAS_ESTUDANTES_URL . 'assets/frontend/js/lista.js',
            array('jquery'),
            LISTAS_ESTUDANTES_VERSION,
            true
        );

        $config = array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'brindesValorMinimo' => (float) LISTAS_BRINDES_VALOR_MINIMO,
            'cartUrl' => wc_get_cart_url(),
        );

        // wp_json_encode preserva os tipos numéricos (wp_localize_script converteria para string)
        wp_add_inline_script('listas-frontend', 'var ListasFrontendConfig = ' . wp_json_encode($config) . ';', 'before');
    }
}
