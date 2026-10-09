<?php
namespace ListasEstudantes\Admin\Ajax;

use ListasEstudantes\Repository\SimilaresRepository;
use ListasEstudantes\Domain\SkuResolver;

if (!defined('ABSPATH')) exit;

/**
 * AJAX admin: gestão de produtos similares (modal do grid de produtos).
 * Alta performance com queries diretas para 60k+ produtos.
 */
final class SimilaresAjax {

    /** @var SimilaresRepository */
    private $similares;

    /** @var SkuResolver */
    private $skuResolver;

    public function __construct(SimilaresRepository $similares, SkuResolver $skuResolver) {
        $this->similares = $similares;
        $this->skuResolver = $skuResolver;
    }

    public function register() {
        add_action('wp_ajax_listas_get_similares_admin', array($this, 'getForAdmin'));
        add_action('wp_ajax_listas_search_similares', array($this, 'search'));
        add_action('wp_ajax_listas_add_similar', array($this, 'add'));
        add_action('wp_ajax_listas_bulk_import_similares', array($this, 'bulkImport'));
        add_action('wp_ajax_listas_remove_similar', array($this, 'remove'));
        add_action('wp_ajax_listas_reorder_similares', array($this, 'reorder'));
    }

    private function guard() {
        check_ajax_referer('listas_produtos_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Sem permissão');
        }
    }

    public function getForAdmin() {
        $this->guard();

        $product_id = absint($_POST['product_id']);
        $product = wc_get_product($product_id);

        if (!$product) {
            wp_send_json_error('Produto não encontrado');
        }

        $similares_ids = $this->similares->getSimilarIds($product_id);

        $similares = array();

        if (!empty($similares_ids)) {
            foreach ($similares_ids as $similar_id) {
                $similar_product = wc_get_product($similar_id);
                if ($similar_product) {
                    $similares[] = array(
                        'id' => $similar_id,
                        'name' => $similar_product->get_name(),
                        'sku' => $similar_product->get_sku() ?: '',
                        'price' => $similar_product->get_price_html(),
                        'image' => wp_get_attachment_image_url($similar_product->get_image_id(), 'woocommerce_thumbnail') ?: wc_placeholder_img_src('woocommerce_thumbnail')
                    );
                }
            }
        }

        wp_send_json_success(array(
            'product_id' => $product_id,
            'product_name' => $product->get_name(),
            'similares' => $similares
        ));
    }

    public function search() {
        $this->guard();

        global $wpdb;

        $search = sanitize_text_field($_POST['search']);
        $exclude_product_id = absint($_POST['exclude_product_id']);

        if (strlen($search) < 2) {
            wp_send_json_success(array());
        }

        // Buscar IDs de produtos já adicionados como similares
        $existing_similares = $this->similares->getSimilarIds($exclude_product_id);

        // Query otimizada diretamente no banco de dados para alta performance
        $search_like = '%' . $wpdb->esc_like($search) . '%';

        // Se a busca for um número, buscar também por ID
        $is_numeric = is_numeric($search);

        if ($is_numeric) {
            $products = $wpdb->get_results($wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts}
                 WHERE post_type = 'product'
                 AND post_status = 'publish'
                 AND ID != %d
                 AND (post_title LIKE %s OR ID = %d)
                 ORDER BY post_title ASC
                 LIMIT 20",
                $exclude_product_id,
                $search_like,
                intval($search)
            ));
        } else {
            $products = $wpdb->get_results($wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts}
                 WHERE post_type = 'product'
                 AND post_status = 'publish'
                 AND ID != %d
                 AND post_title LIKE %s
                 ORDER BY post_title ASC
                 LIMIT 20",
                $exclude_product_id,
                $search_like
            ));
        }

        $result_ids = array();
        foreach ($products as $product_row) {
            $result_ids[] = (int) $product_row->ID;
        }

        // Busca por SKU (aceita SKU de variação -> produto pai e regra OD-),
        // com prioridade no topo dos resultados
        $sku_hit = $this->skuResolver->resolveProductId($search);
        if ($sku_hit && $sku_hit !== $exclude_product_id && !in_array($sku_hit, $result_ids)) {
            array_unshift($result_ids, $sku_hit);
        }

        $result_ids = array_slice($result_ids, 0, 20);

        $result = array();

        foreach ($result_ids as $found_id) {
            $product = wc_get_product($found_id);
            if ($product) {
                $result[] = array(
                    'id' => $found_id,
                    'name' => $product->get_name(),
                    'sku' => $product->get_sku() ?: '',
                    'price' => $product->get_price_html(),
                    'image' => wp_get_attachment_image_url($product->get_image_id(), 'woocommerce_thumbnail') ?: wc_placeholder_img_src('woocommerce_thumbnail'),
                    'is_similar' => in_array($found_id, $existing_similares)
                );
            }
        }

        wp_send_json_success($result);
    }

    public function add() {
        $this->guard();

        $product_id = absint($_POST['product_id']);
        $similar_id = absint($_POST['similar_id']);

        if (!$product_id || !$similar_id) {
            wp_send_json_error('IDs inválidos');
        }

        if ($product_id === $similar_id) {
            wp_send_json_error('Não é possível adicionar o mesmo produto como similar');
        }

        if ($this->similares->exists($product_id, $similar_id)) {
            wp_send_json_error('Produto já adicionado como similar');
        }

        $inserted = $this->similares->insert($product_id, $similar_id, $this->similares->nextPosition($product_id));

        if ($inserted) {
            wp_send_json_success(array('message' => 'Produto similar adicionado'));
        } else {
            wp_send_json_error('Erro ao adicionar produto similar');
        }
    }

    public function bulkImport() {
        $this->guard();

        $product_id = absint($_POST['product_id']);
        $skus = isset($_POST['skus']) ? json_decode(stripslashes($_POST['skus']), true) : array();

        if (!$product_id || empty($skus) || !is_array($skus)) {
            wp_send_json_error('Dados inválidos');
        }

        $added = 0;
        $errors = array();

        // Buscar produtos por SKU (aceita SKU de variação -> produto pai e regra OD-)
        foreach ($skus as $sku) {
            $sku = trim($sku);
            if (empty($sku)) continue;

            $similar_id = $this->skuResolver->resolveProductId($sku);

            if (!$similar_id) {
                $errors[] = "SKU '{$sku}' não encontrado";
                continue;
            }

            // Verificar se já existe
            if ($this->similares->exists($product_id, $similar_id)) {
                continue; // Já existe, pular
            }

            // Verificar se não é o mesmo produto
            if ($product_id === $similar_id) {
                continue;
            }

            $inserted = $this->similares->insert($product_id, $similar_id, $this->similares->nextPosition($product_id));

            if ($inserted) {
                $added++;
            }
        }

        wp_send_json_success(array(
            'message' => 'Importação concluída',
            'added' => $added,
            'errors' => $errors
        ));
    }

    public function remove() {
        $this->guard();

        $product_id = absint($_POST['product_id']);
        $similar_id = absint($_POST['similar_id']);

        if (!$product_id || !$similar_id) {
            wp_send_json_error('IDs inválidos');
        }

        $deleted = $this->similares->delete($product_id, $similar_id);

        if ($deleted) {
            wp_send_json_success(array('message' => 'Produto similar removido'));
        } else {
            wp_send_json_error('Erro ao remover produto similar');
        }
    }

    public function reorder() {
        $this->guard();

        $product_id = absint($_POST['product_id']);
        $order = isset($_POST['order']) ? json_decode(stripslashes($_POST['order']), true) : array();

        if (!$product_id || empty($order)) {
            wp_send_json_error('Dados inválidos');
        }

        // Atualizar posição de cada produto similar
        foreach ($order as $item) {
            $similar_id = absint($item['similar_id']);
            $position = absint($item['position']);

            $this->similares->updatePosition($product_id, $similar_id, $position);
        }

        wp_send_json_success(array('message' => 'Ordem atualizada com sucesso'));
    }
}
