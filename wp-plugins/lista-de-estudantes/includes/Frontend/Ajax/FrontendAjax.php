<?php
namespace ListasEstudantes\Frontend\Ajax;

use ListasEstudantes\Repository\SimilaresRepository;
use ListasEstudantes\Domain\DiscountRules;
use ListasEstudantes\Domain\BrindeRules;

if (!defined('ABSPATH')) exit;

/**
 * AJAX público do template das listas (logado + visitante).
 *
 * NOTA: estes handlers não verificam nonce — comportamento legado
 * preservado no refactor. Hardening futuro: emitir um nonce no
 * ListasFrontendConfig e validar aqui em conjunto com o lista.js.
 */
final class FrontendAjax {

    /** @var SimilaresRepository */
    private $similares;

    public function __construct(SimilaresRepository $similares) {
        $this->similares = $similares;
    }

    public function register() {
        add_action('wp_ajax_listas_ver_similares', array($this, 'verSimilares'));
        add_action('wp_ajax_nopriv_listas_ver_similares', array($this, 'verSimilares'));

        add_action('wp_ajax_listas_has_similares', array($this, 'hasSimilares'));
        add_action('wp_ajax_nopriv_listas_has_similares', array($this, 'hasSimilares'));

        add_action('wp_ajax_listas_find_variation', array($this, 'findVariation'));
        add_action('wp_ajax_nopriv_listas_find_variation', array($this, 'findVariation'));

        add_action('wp_ajax_listas_add_brinde_to_cart', array($this, 'addBrindeToCart'));
        add_action('wp_ajax_nopriv_listas_add_brinde_to_cart', array($this, 'addBrindeToCart'));

        add_action('wp_ajax_listas_check_brinde_in_cart', array($this, 'checkBrindeInCart'));
        add_action('wp_ajax_nopriv_listas_check_brinde_in_cart', array($this, 'checkBrindeInCart'));

        add_action('wp_ajax_listas_add_to_cart', array($this, 'addToCart'));
        add_action('wp_ajax_nopriv_listas_add_to_cart', array($this, 'addToCart'));
    }

    public function verSimilares() {
        $product_id = intval($_POST['product_id']);

        if (!$product_id) {
            wp_send_json_error('ID inválido');
        }

        $product = wc_get_product($product_id);
        if (!$product) {
            wp_send_json_error('Produto não encontrado');
        }

        // Buscar APENAS produtos similares manuais (sem fallback para categoria)
        $similares_ids = $this->similares->getSimilarIds($product_id, 10);

        $similares = array();

        if (!empty($similares_ids)) {
            foreach ($similares_ids as $similar_id) {
                $similar_product = wc_get_product($similar_id);

                if ($similar_product && $similar_product->is_in_stock()) {
                    $similares[] = array(
                        'id' => $similar_id,
                        'name' => $similar_product->get_name(),
                        'price' => $similar_product->get_price_html(),
                        'price_value' => $similar_product->get_price(),
                        'sku' => $similar_product->get_sku(),
                        'image' => wp_get_attachment_image_url($similar_product->get_image_id(), 'woocommerce_thumbnail') ?: wc_placeholder_img_src('woocommerce_thumbnail'),
                        'link' => get_permalink($similar_id)
                    );
                }
            }
        }

        wp_send_json_success($similares);
    }

    public function hasSimilares() {
        $product_id = intval($_POST['product_id']);

        if (!$product_id) {
            wp_send_json_error('ID inválido');
        }

        $count = $this->similares->countFor($product_id);

        wp_send_json_success(array(
            'has_similares' => $count > 0,
            'count' => $count
        ));
    }

    public function findVariation() {
        $product_id = intval($_POST['product_id']);
        $attributes = isset($_POST['attributes']) ? $_POST['attributes'] : array();

        $product = wc_get_product($product_id);
        if (!$product || !$product->is_type('variable')) {
            wp_send_json_error('Produto não é variável');
            return;
        }

        // Buscar todas as variações disponíveis
        $available_variations = $product->get_available_variations();

        if (empty($available_variations)) {
            wp_send_json_error('Nenhuma variação disponível para este produto');
            return;
        }

        // Normalizar atributos recebidos - criar múltiplas formas de busca
        $normalized_attributes = array();
        foreach ($attributes as $key => $value) {
            // Limpar e normalizar o nome do atributo
            $clean_key = str_replace('attribute_', '', $key);
            $clean_key_slug = sanitize_title($clean_key);

            // Normalizar o valor
            $clean_value = sanitize_title($value);
            $clean_value_lower = strtolower($value);

            // Criar múltiplas chaves possíveis para busca flexível
            $possible_keys = array(
                'attribute_' . $clean_key,
                'attribute_pa_' . $clean_key,
                'attribute_' . $clean_key_slug,
                'attribute_pa_' . $clean_key_slug,
                $clean_key,
                'pa_' . $clean_key
            );

            // Adicionar todas as combinações possíveis
            foreach ($possible_keys as $possible_key) {
                $normalized_attributes[$possible_key] = $clean_value;
                $normalized_attributes[$possible_key . '_raw'] = $value;
                $normalized_attributes[$possible_key . '_lower'] = $clean_value_lower;
            }
        }

        // Procurar variação correspondente através de todas as variações disponíveis
        foreach ($available_variations as $variation_data) {
            if (!$variation_data['is_purchasable'] || !$variation_data['is_in_stock']) {
                continue;
            }

            $variation_attributes = $variation_data['attributes'];
            $match = true;
            $matched_count = 0;
            $required_count = 0;

            // Verificar cada atributo necessário da variação
            foreach ($variation_attributes as $attr_key => $attr_value) {
                // Se o atributo está vazio, significa "Any" - não precisa fazer match
                if (empty($attr_value)) {
                    continue;
                }

                $required_count++;
                $found = false;

                // Normalizar o valor do atributo da variação
                $attr_value_slug = sanitize_title($attr_value);
                $attr_value_lower = strtolower($attr_value);

                // Verificar se temos este atributo nos dados enviados
                foreach ($normalized_attributes as $norm_key => $norm_value) {
                    // Comparar chaves (com diferentes formatos)
                    $key_match = false;
                    if ($attr_key === $norm_key) {
                        $key_match = true;
                    } elseif (str_replace('attribute_', '', $attr_key) === str_replace('attribute_', '', $norm_key)) {
                        $key_match = true;
                    } elseif (str_replace('pa_', '', str_replace('attribute_', '', $attr_key)) ===
                              str_replace('pa_', '', str_replace('attribute_', '', $norm_key))) {
                        $key_match = true;
                    }

                    if ($key_match) {
                        // Comparar valores (case insensitive e com slug)
                        if ($attr_value_slug === sanitize_title($norm_value) ||
                            $attr_value === $norm_value ||
                            $attr_value_lower === strtolower($norm_value) ||
                            strcasecmp($attr_value, $norm_value) === 0) {
                            $found = true;
                            $matched_count++;
                            break;
                        }
                    }
                }

                if (!$found) {
                    $match = false;
                    break;
                }
            }

            // Se encontrou match e todos os atributos necessários foram correspondidos
            if ($match && $matched_count === $required_count && $required_count > 0) {
                $variation = wc_get_product($variation_data['variation_id']);
                if ($variation) {
                    wp_send_json_success(array(
                        'variation_id' => $variation_data['variation_id'],
                        'price' => floatval($variation->get_price()),
                        'price_html' => $variation->get_price_html(),
                        'variation_name' => $variation->get_name(),
                        'discount_percent' => DiscountRules::productDiscountPercent($variation)
                    ));
                    return;
                }
            }
        }

        // Se não encontrou, tentar usar função nativa do WooCommerce como fallback
        $variation_data = array();
        $product_attributes = $product->get_variation_attributes();

        foreach ($attributes as $key => $value) {
            $attr_name = str_replace('attribute_', '', $key);
            foreach ($product_attributes as $prod_attr_name => $prod_attr_options) {
                if ($attr_name === $prod_attr_name ||
                    $attr_name === str_replace('pa_', '', $prod_attr_name) ||
                    'pa_' . $attr_name === $prod_attr_name) {
                    $variation_data['attribute_' . $prod_attr_name] = $value;
                    break;
                }
            }
        }

        if (!empty($variation_data)) {
            $data_store = \WC_Data_Store::load('product');
            $variation_id = $data_store->find_matching_product_variation($product, $variation_data);

            if ($variation_id) {
                $variation = wc_get_product($variation_id);
                if ($variation && $variation->is_purchasable() && $variation->is_in_stock()) {
                    wp_send_json_success(array(
                        'variation_id' => $variation_id,
                        'price' => floatval($variation->get_price()),
                        'price_html' => $variation->get_price_html(),
                        'variation_name' => $variation->get_name(),
                        'discount_percent' => DiscountRules::productDiscountPercent($variation)
                    ));
                    return;
                }
            }
        }

        // Se ainda não encontrou, retornar erro com informações de debug
        wp_send_json_error(array(
            'message' => 'Variação não encontrada para os atributos selecionados',
            'attributes_received' => $attributes,
            'normalized_attributes' => $normalized_attributes,
            'variations_count' => count($available_variations)
        ));
    }

    public function checkBrindeInCart() {
        if (!defined('WC_ABSPATH')) {
            wp_send_json_error('WooCommerce não está ativo');
            return;
        }

        wp_send_json_success(array('has_brinde' => BrindeRules::cartHasBrinde()));
    }

    public function addBrindeToCart() {
        if (!defined('WC_ABSPATH')) {
            wp_send_json_error('WooCommerce não está ativo');
            return;
        }

        $product_id = intval($_POST['product_id']);
        $quantity = intval($_POST['quantity']) ?: 1;

        if (!$product_id) {
            wp_send_json_error('ID de produto inválido');
            return;
        }

        // Verificar se já tem brinde no carrinho
        if (BrindeRules::cartHasBrinde()) {
            wp_send_json_error('Você já escolheu seu brinde! Apenas 1 brinde por lista.');
            return;
        }

        // Verificar se o produto pertence à categoria de brindes
        $product = wc_get_product($product_id);
        if (!$product) {
            wp_send_json_error('Produto não encontrado');
            return;
        }

        if (!BrindeRules::isBrindeProduct($product_id)) {
            wp_send_json_error('Este produto não é um brinde válido');
            return;
        }

        if (!$product->is_in_stock()) {
            wp_send_json_error('Brinde fora de estoque');
            return;
        }

        // Adicionar ao carrinho com flag especial para aplicar desconto de 100%
        $cart_item_data = array(
            'is_brinde' => true,
            'brinde_original_price' => floatval($product->get_price())
        );

        $cart_item_key = WC()->cart->add_to_cart($product_id, $quantity, 0, array(), $cart_item_data);

        if ($cart_item_key) {
            WC()->cart->calculate_totals();

            wp_send_json_success(array(
                'message' => 'Brinde adicionado ao carrinho',
                'cart_count' => WC()->cart->get_cart_contents_count(),
                'cart_hash' => WC()->cart->get_cart_hash()
            ));
        } else {
            $error_message = 'Não foi possível adicionar o brinde ao carrinho';
            if (wc_notice_count('error') > 0) {
                $notices = wc_get_notices('error');
                if (!empty($notices)) {
                    $error_message = $notices[0]['notice'];
                }
            }
            wp_send_json_error($error_message);
        }
    }

    public function addToCart() {
        if (!defined('WC_ABSPATH')) {
            wp_send_json_error('WooCommerce não está ativo');
            return;
        }

        $product_id = intval($_POST['product_id']);
        $variation_id = isset($_POST['variation_id']) ? intval($_POST['variation_id']) : 0;
        $quantity = intval($_POST['quantity']) ?: 1;

        if (!$product_id) {
            wp_send_json_error('ID de produto inválido');
            return;
        }

        $product = wc_get_product($product_id);
        if (!$product) {
            wp_send_json_error('Produto não encontrado');
            return;
        }

        if (!$product->is_in_stock()) {
            wp_send_json_error('Produto fora de estoque');
            return;
        }

        // Se for produto variável, usar variation_id
        if ($variation_id > 0) {
            $variation = wc_get_product($variation_id);
            if (!$variation) {
                wp_send_json_error('Variação não encontrada');
                return;
            }

            if (!$variation->is_in_stock()) {
                wp_send_json_error('Variação fora de estoque');
                return;
            }

            // Pegar atributos da variação
            $variation_attributes = $variation->get_variation_attributes();
            $cart_item_data = array();

            // Adicionar ao carrinho com atributos
            $cart_item_key = WC()->cart->add_to_cart($product_id, $quantity, $variation_id, $variation_attributes, $cart_item_data);
        } else {
            // Verificar se é produto variável sem variação selecionada
            if ($product->is_type('variable')) {
                wp_send_json_error('Por favor, selecione uma variação do produto');
                return;
            }

            $cart_item_key = WC()->cart->add_to_cart($product_id, $quantity);
        }

        if ($cart_item_key) {
            WC()->cart->calculate_totals();

            wp_send_json_success(array(
                'message' => 'Produto adicionado ao carrinho',
                'cart_count' => WC()->cart->get_cart_contents_count(),
                'cart_hash' => WC()->cart->get_cart_hash(),
                'fragments' => apply_filters('woocommerce_add_to_cart_fragments', array())
            ));
        } else {
            $error_message = 'Não foi possível adicionar o produto ao carrinho';
            if (wc_notice_count('error') > 0) {
                $notices = wc_get_notices('error');
                if (!empty($notices)) {
                    $error_message = $notices[0]['notice'];
                }
            }
            wp_send_json_error($error_message);
        }
    }
}
