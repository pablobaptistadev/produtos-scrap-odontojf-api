<?php
namespace ListasEstudantes\Setup;

if (!defined('ABSPATH')) exit;

/**
 * Custom post type lista_estudante.
 */
final class PostType {

    public function register() {
        add_action('init', array(__CLASS__, 'registerPostType'));
    }

    public static function registerPostType() {
        $labels = array(
            'name'               => 'Listas',
            'singular_name'      => 'Lista',
            'menu_name'          => 'Listas',
            'add_new'            => 'Nova Lista',
            'add_new_item'       => 'Adicionar Nova Lista',
            'edit_item'          => 'Editar Lista',
            'new_item'           => 'Nova Lista',
            'view_item'          => 'Ver Lista',
            'search_items'       => 'Buscar Listas',
            'not_found'          => 'Nenhuma lista encontrada',
            'not_found_in_trash' => 'Nenhuma lista na lixeira',
        );

        $args = array(
            'labels'              => $labels,
            'public'              => false,
            'show_ui'             => true,
            'show_in_menu'        => false,
            'capability_type'     => 'post',
            'hierarchical'        => false,
            'supports'            => array('title'),
            'has_archive'         => false,
            'rewrite'             => false,
            'query_var'           => false,
        );

        register_post_type('lista_estudante', $args);
    }
}
