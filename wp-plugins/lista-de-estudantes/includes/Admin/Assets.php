<?php
namespace ListasEstudantes\Admin;

if (!defined('ABSPATH')) exit;

/**
 * CSS/JS do admin (telas do CPT lista_estudante).
 */
final class Assets {

    public function register() {
        add_action('admin_enqueue_scripts', array($this, 'enqueue'));
    }

    public function enqueue($hook) {
        global $post_type;

        if ($post_type !== 'lista_estudante') {
            return;
        }

        wp_enqueue_style('montserrat-font', 'https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap');

        wp_enqueue_style(
            'listas-admin',
            LISTAS_ESTUDANTES_URL . 'assets/admin/css/admin.css',
            array(),
            LISTAS_ESTUDANTES_VERSION
        );

        // O JS depende da tela de edição (precisa do $post)
        if ($hook !== 'post.php' && $hook !== 'post-new.php') {
            return;
        }

        wp_enqueue_script(
            'listas-admin',
            LISTAS_ESTUDANTES_URL . 'assets/admin/js/admin.js',
            array('jquery', 'jquery-ui-sortable'),
            LISTAS_ESTUDANTES_VERSION,
            true
        );

        $post = get_post();
        $config = array(
            'postId' => $post ? (int) $post->ID : 0,
            'categoriaId' => $post ? (int) get_post_meta($post->ID, '_listas_categoria_id', true) : 0,
            'nonce' => wp_create_nonce('listas_produtos_nonce'),
        );

        // wp_json_encode preserva os tipos numéricos (wp_localize_script converteria para string)
        wp_add_inline_script('listas-admin', 'var ListasAdminConfig = ' . wp_json_encode($config) . ';', 'before');
    }
}
