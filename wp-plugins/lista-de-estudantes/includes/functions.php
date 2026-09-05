<?php
/**
 * Wrappers globais de compatibilidade.
 *
 * Mantidos porque snippets de tema/Elementor podem chamar estas funções
 * diretamente. A implementação real vive nas classes de serviço.
 */

if (!defined('ABSPATH')) exit;

if (!function_exists('listas_get_product_discount_percent')) {
    function listas_get_product_discount_percent($product) {
        return \ListasEstudantes\Domain\DiscountRules::productDiscountPercent($product);
    }
}

if (!function_exists('listas_get_lista_info')) {
    function listas_get_lista_info() {
        return \ListasEstudantes\Frontend\TemplateController::getListaInfo();
    }
}

if (!function_exists('listas_sync_categoria')) {
    function listas_sync_categoria($post_id) {
        \ListasEstudantes\Domain\CategorySync::sync($post_id);
    }
}
