<?php
namespace ListasEstudantes\Domain;

if (!defined('ABSPATH')) exit;

/**
 * Sincroniza a lista (CPT lista_estudante) com sua categoria WooCommerce
 * (filha de LISTAS_PARENT_CAT_ID) e remove a categoria ao deletar a lista.
 */
final class CategorySync {

    public function register() {
        add_action('before_delete_post', array(__CLASS__, 'onDeletePost'));
    }

    public static function sync($post_id) {
        $post = get_post($post_id);
        if (!$post) return;

        $categoria_id = get_post_meta($post_id, '_listas_categoria_id', true);
        $escola = trim(get_post_meta($post_id, '_listas_escola', true));
        $turma = trim(get_post_meta($post_id, '_listas_turma', true));
        $periodo = trim(get_post_meta($post_id, '_listas_disciplina', true));
        $cidade = trim(get_post_meta($post_id, '_listas_cidade', true));
        $ordem = get_post_meta($post_id, '_listas_ordem', true) ?: 0;

        $titulo_partes = array_filter(array($escola, $turma, $periodo, $cidade));
        $cat_name = implode(' - ', $titulo_partes);

        if (empty($cat_name)) {
            $cat_name = trim($post->post_title);
        }
        if (empty($cat_name)) {
            $cat_name = 'Lista ' . $post_id;
        }

        $slug_partes = array_filter(array($escola, $turma, $periodo));
        $slug_base = implode(' - ', $slug_partes);

        $cat_slug = sanitize_title($slug_base);
        if (empty($cat_slug)) {
            $cat_slug = 'lista-' . $post_id;
        }

        $cat_args = array(
            'parent' => LISTAS_PARENT_CAT_ID,
            'slug' => $cat_slug,
        );

        if ($categoria_id && term_exists($categoria_id, 'product_cat')) {
            wp_update_term($categoria_id, 'product_cat', array(
                'name' => $cat_name,
                'slug' => $cat_slug,
                'parent' => LISTAS_PARENT_CAT_ID
            ));
        } else {
            $term = wp_insert_term($cat_name, 'product_cat', $cat_args);

            if (is_wp_error($term) && $term->get_error_code() === 'term_exists') {
                $categoria_id = $term->get_error_data('term_exists');
            } elseif (is_wp_error($term)) {
                // Tentar novamente com slug único
                $cat_args['slug'] = sanitize_title($cat_name . '-' . $post_id);
                $term = wp_insert_term($cat_name, 'product_cat', $cat_args);
            }

            if (!is_wp_error($term)) {
                $categoria_id = $term['term_id'];
            }

            if ($categoria_id) {
                update_post_meta($post_id, '_listas_categoria_id', $categoria_id);
            }
        }

        if ($categoria_id) {
            update_term_meta($categoria_id, 'order', $ordem);
        }
    }

    public static function onDeletePost($post_id) {
        if (get_post_type($post_id) !== 'lista_estudante') return;

        $categoria_id = get_post_meta($post_id, '_listas_categoria_id', true);
        if ($categoria_id) {
            wp_delete_term($categoria_id, 'product_cat');
        }
    }
}
