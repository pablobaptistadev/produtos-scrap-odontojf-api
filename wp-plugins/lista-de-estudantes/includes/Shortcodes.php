<?php
namespace ListasEstudantes;

if (!defined('ABSPATH')) exit;

/**
 * Shortcodes públicos do plugin.
 */
final class Shortcodes {

    public function register() {
        add_shortcode('lista_categoria_url', array($this, 'categoriaUrl'));
    }

    public function categoriaUrl($atts) {
        $atts = shortcode_atts(array(
            'id' => 0,
        ), $atts, 'lista_categoria_url');

        $lista_id = intval($atts['id']);

        if (!$lista_id && is_singular('lista_estudante')) {
            $lista_id = get_the_ID();
        }

        if (!$lista_id) {
            return '';
        }

        $categoria_id = get_post_meta($lista_id, '_listas_categoria_id', true);
        if (!$categoria_id) {
            return '';
        }

        $link = get_term_link((int) $categoria_id, 'product_cat');
        if (is_wp_error($link)) {
            return '';
        }

        // Retornar sem protocolo; Elementor adiciona automaticamente
        $normalized = preg_replace('#^https?://#i', '', $link);

        return esc_html($normalized);
    }
}
