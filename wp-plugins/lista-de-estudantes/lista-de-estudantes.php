<?php
/**
 * Plugin Name: Listas de Estudantes
 * Description: Sistema de listas para estudantes com integração WooCommerce
 * Version: 2.2.1
 * Author: Programador
 */

if (!defined('ABSPATH')) exit;

define('LISTAS_ESTUDANTES_VERSION', '2.2.1');
define('LISTAS_PARENT_CAT_ID', 91693);
define('LISTAS_BRINDES_CAT_ID', 91694);
define('LISTAS_BRINDES_VALOR_MINIMO', 1000);
define('LISTAS_ESTUDANTES_FILE', __FILE__);
define('LISTAS_ESTUDANTES_PATH', plugin_dir_path(__FILE__));
define('LISTAS_ESTUDANTES_URL', plugin_dir_url(__FILE__));

require LISTAS_ESTUDANTES_PATH . 'includes/Autoloader.php';
ListasEstudantes\Autoloader::register();

require LISTAS_ESTUDANTES_PATH . 'includes/functions.php';

// Hooks de ativação precisam ficar no arquivo principal do plugin
register_activation_hook(__FILE__, array('ListasEstudantes\\Setup\\Activation', 'activate'));
register_deactivation_hook(__FILE__, array('ListasEstudantes\\Setup\\Activation', 'deactivate'));

// A checagem de WooCommerce só é confiável em plugins_loaded: no carregamento
// direto do arquivo a ordem entre plugins ativos não é garantida, e checar
// class_exists('WooCommerce') aqui pode falhar mesmo com o WooCommerce ativo.
add_action('plugins_loaded', 'listas_estudantes_bootstrap', 20);

function listas_estudantes_bootstrap() {
    if (!class_exists('WooCommerce')) {
        return;
    }
    ListasEstudantes\Plugin::boot();
}
