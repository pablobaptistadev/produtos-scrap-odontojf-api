<?php
namespace ListasEstudantes\Setup;

if (!defined('ABSPATH')) exit;

/**
 * Menu "Estudantes" no admin.
 */
final class AdminMenu {

    public function register() {
        add_action('admin_menu', array($this, 'createMenu'));
    }

    public function createMenu() {
        add_menu_page(
            'Estudantes',
            'Estudantes',
            'manage_woocommerce',
            'estudantes-main',
            array($this, 'redirectToListas'),
            'dashicons-welcome-learn-more',
            30
        );

        add_submenu_page(
            'estudantes-main',
            'Listas',
            'Listas',
            'manage_woocommerce',
            'edit.php?post_type=lista_estudante'
        );

        remove_submenu_page('estudantes-main', 'estudantes-main');
    }

    public function redirectToListas() {
        wp_redirect(admin_url('edit.php?post_type=lista_estudante'));
        exit;
    }
}
