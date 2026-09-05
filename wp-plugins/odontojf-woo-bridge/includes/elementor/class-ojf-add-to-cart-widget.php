<?php
/**
 * Widget Elementor "Carrinho OdontoJF" (>= 1.0.46).
 *
 * Substitui o "Colocar no carrinho" do Elementor Pro. Renderiza o MESMO
 * template do WooCommerce (woocommerce_template_single_add_to_cart), então o
 * seletor de variação, o preço e o botão continuam idênticos ao que o tema já
 * estiliza — nenhuma marcação nova por baixo do botão.
 *
 * O que ele acrescenta:
 *   • esconde o preço que o bloco da variação repete (a página já mostra o
 *     preço via [preco_info] ou pelo widget de preço do Elementor);
 *   • adiciona ao carrinho por AJAX, com estado de carregando no próprio botão.
 *
 * Sobre o AJAX: WC_AJAX::add_to_cart aceita o id da VARIAÇÃO em product_id e
 * deriva o pai e os atributos sozinho (verificado no core), então não é preciso
 * montar o array de atributos no cliente.
 */

if (!defined('ABSPATH')) exit;

class OJF_Add_To_Cart_Widget extends \Elementor\Widget_Base {

    public function get_name() {
        return 'ojf_add_to_cart';
    }

    public function get_title() {
        return esc_html__('Carrinho OdontoJF', 'odontojf');
    }

    public function get_icon() {
        return 'eicon-product-add-to-cart';
    }

    public function get_categories() {
        return array('woocommerce-elements', 'general');
    }

    public function get_keywords() {
        return array('carrinho', 'cart', 'comprar', 'variação', 'woocommerce', 'odontojf');
    }

    /** O tema e o WooCommerce já carregam o que este widget usa. */
    public function get_script_depends() {
        return array('wc-add-to-cart-variation');
    }

    protected function register_controls() {

        $this->start_controls_section('secao_conteudo', array(
            'label' => esc_html__('Conteúdo', 'odontojf'),
        ));

        $this->add_control('ocultar_preco_variacao', array(
            'label' => esc_html__('Ocultar o preço da variação', 'odontojf'),
            'description' => esc_html__('O bloco de variação do WooCommerce repete o preço. Deixe ligado se a página já mostra o preço em outro lugar.', 'odontojf'),
            'type' => \Elementor\Controls_Manager::SWITCHER,
            'default' => 'yes',
        ));

        $this->add_control('ocultar_descricao_variacao', array(
            'label' => esc_html__('Ocultar a descrição da variação', 'odontojf'),
            'type' => \Elementor\Controls_Manager::SWITCHER,
            'default' => '',
        ));

        $this->add_control('ajax', array(
            'label' => esc_html__('Adicionar por AJAX', 'odontojf'),
            'description' => esc_html__('Sem recarregar a página, com estado de carregando no botão.', 'odontojf'),
            'type' => \Elementor\Controls_Manager::SWITCHER,
            'default' => 'yes',
        ));

        $this->add_control('texto_carregando', array(
            'label' => esc_html__('Texto ao adicionar', 'odontojf'),
            'type' => \Elementor\Controls_Manager::TEXT,
            'default' => esc_html__('Atualizando carrinho...', 'odontojf'),
            'condition' => array('ajax' => 'yes'),
        ));

        $this->add_control('texto_sucesso', array(
            'label' => esc_html__('Texto ao concluir', 'odontojf'),
            'type' => \Elementor\Controls_Manager::TEXT,
            'default' => esc_html__('Adicionado ao carrinho', 'odontojf'),
            'condition' => array('ajax' => 'yes'),
        ));

        $this->end_controls_section();
    }

    protected function render() {
        if (!function_exists('wc_get_product')) return;

        $settings = $this->get_settings_for_display();
        $product  = $this->resolveProduct();

        if (!$product) {
            if (\Elementor\Plugin::$instance->editor->is_edit_mode()) {
                echo '<div style="padding:16px;border:1px dashed #c3c4c7;color:#646970;font:13px/1.5 sans-serif">'
                   . esc_html__('Abra este template com um produto na pré-visualização para ver o carrinho.', 'odontojf')
                   . '</div>';
            }
            return;
        }

        // woocommerce_template_single_add_to_cart() lê o $product global.
        $previous = isset($GLOBALS['product']) ? $GLOBALS['product'] : null;
        $GLOBALS['product'] = $product;

        $classes = array('ojf-atc');
        if ($settings['ocultar_preco_variacao'] === 'yes')    $classes[] = 'ojf-atc--sem-preco';
        if ($settings['ocultar_descricao_variacao'] === 'yes') $classes[] = 'ojf-atc--sem-descricao';
        if ($settings['ajax'] === 'yes')                       $classes[] = 'ojf-atc--ajax';

        printf(
            '<div class="%s" data-loading="%s" data-done="%s">',
            esc_attr(implode(' ', $classes)),
            esc_attr($settings['texto_carregando']),
            esc_attr($settings['texto_sucesso'])
        );
        woocommerce_template_single_add_to_cart();
        echo '</div>';

        $GLOBALS['product'] = $previous;
    }

    /**
     * O produto da página. No editor o post consultado costuma ser o template,
     * não um produto, então cai no preview de produto que o Elementor define.
     */
    private function resolveProduct() {
        $product = wc_get_product(get_the_ID());
        if ($product) return $product;

        $product = wc_get_product(get_queried_object_id());
        if ($product) return $product;

        if (class_exists('\Elementor\Plugin') && \Elementor\Plugin::$instance->editor->is_edit_mode()) {
            $recent = get_posts(array('post_type' => 'product', 'posts_per_page' => 1, 'post_status' => 'publish'));
            if ($recent) return wc_get_product($recent[0]->ID);
        }

        return null;
    }
}
