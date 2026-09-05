<?php
/**
 * Widget Elementor "Campo OdontoJF" (>= 1.0.47).
 *
 * Existe porque um Dynamic Field apontado para `_odontojf_variation_title` volta
 * SEMPRE vazio num loop de produtos: o post do loop é o produto PAI e o meta
 * mora na VARIAÇÃO. Não há como chegar lá por meta field — precisa descer aos
 * filhos, que é o que este widget faz.
 *
 * Um widget por campo, como o Dynamic Field: você arrasta quantos precisar e
 * cada um escolhe o que exibe. Campo sem valor não imprime nada (nem a tag),
 * então não sobra espaço em branco no card.
 *
 * Custo: os títulos saem de get_post_meta() sobre get_children(), não de
 * get_available_variations() — esta última monta o objeto inteiro de cada
 * variação e num loop de 30 produtos variáveis derruba a página.
 */

if (!defined('ABSPATH')) exit;

class OJF_Field_Widget extends \Elementor\Widget_Base {

    const META_TITLE = '_odontojf_variation_title';

    public function get_name() {
        return 'ojf_campo';
    }

    public function get_title() {
        return esc_html__('Campo OdontoJF', 'odontojf');
    }

    public function get_icon() {
        return 'eicon-product-title';
    }

    public function get_categories() {
        return array('woocommerce-elements', 'general');
    }

    public function get_keywords() {
        return array('titulo', 'variação', 'preço', 'sku', 'produto', 'odontojf');
    }

    protected function register_controls() {

        $this->start_controls_section('secao', array('label' => esc_html__('Conteúdo', 'odontojf')));

        $this->add_control('campo', array(
            'label' => esc_html__('Campo', 'odontojf'),
            'type' => \Elementor\Controls_Manager::SELECT,
            'default' => 'titulo_variacao',
            'options' => array(
                'titulo_produto'  => esc_html__('Título do produto', 'odontojf'),
                'titulo_variacao' => esc_html__('Título da variação', 'odontojf'),
                'preco'           => esc_html__('Preço', 'odontojf'),
                'sku'             => esc_html__('SKU', 'odontojf'),
                'peso'            => esc_html__('Peso', 'odontojf'),
                'dimensoes'       => esc_html__('Dimensões', 'odontojf'),
            ),
        ));

        $this->add_control('origem', array(
            'label' => esc_html__('Qual variação', 'odontojf'),
            'type' => \Elementor\Controls_Manager::SELECT,
            'default' => 'primeira',
            'options' => array(
                'primeira' => esc_html__('A primeira disponível', 'odontojf'),
                'barata'   => esc_html__('A mais barata', 'odontojf'),
                'todas'    => esc_html__('Todas (juntas)', 'odontojf'),
            ),
            'condition' => array('campo!' => 'titulo_produto'),
            'description' => esc_html__('Numa listagem não há variação escolhida pelo cliente, então é preciso dizer qual mostrar.', 'odontojf'),
        ));

        $this->add_control('separador', array(
            'label' => esc_html__('Separador', 'odontojf'),
            'type' => \Elementor\Controls_Manager::TEXT,
            'default' => ' · ',
            'condition' => array('origem' => 'todas', 'campo!' => 'titulo_produto'),
        ));

        $this->add_control('maximo', array(
            'label' => esc_html__('Máximo de itens', 'odontojf'),
            'type' => \Elementor\Controls_Manager::NUMBER,
            'default' => 4,
            'min' => 1,
            'max' => 30,
            'condition' => array('origem' => 'todas', 'campo!' => 'titulo_produto'),
        ));

        $this->add_control('prefixo', array(
            'label' => esc_html__('Antes', 'odontojf'),
            'type' => \Elementor\Controls_Manager::TEXT,
        ));

        $this->add_control('sufixo', array(
            'label' => esc_html__('Depois', 'odontojf'),
            'type' => \Elementor\Controls_Manager::TEXT,
        ));

        $this->add_control('tag', array(
            'label' => esc_html__('Tag HTML', 'odontojf'),
            'type' => \Elementor\Controls_Manager::SELECT,
            'default' => 'div',
            'options' => array(
                'div' => 'div', 'span' => 'span', 'p' => 'p',
                'h2' => 'H2', 'h3' => 'H3', 'h4' => 'H4', 'h5' => 'H5', 'strong' => 'strong',
            ),
        ));

        $this->add_control('link', array(
            'label' => esc_html__('Link para o produto', 'odontojf'),
            'type' => \Elementor\Controls_Manager::SWITCHER,
            'default' => '',
        ));

        $this->add_control('ocultar_vazio', array(
            'label' => esc_html__('Ocultar se vazio', 'odontojf'),
            'type' => \Elementor\Controls_Manager::SWITCHER,
            'default' => 'yes',
            'description' => esc_html__('Sem valor, não imprime nem a tag — o card não fica com buraco.', 'odontojf'),
        ));

        $this->end_controls_section();

        $this->start_controls_section('secao_estilo', array(
            'label' => esc_html__('Estilo', 'odontojf'),
            'tab' => \Elementor\Controls_Manager::TAB_STYLE,
        ));

        $this->add_control('cor', array(
            'label' => esc_html__('Cor', 'odontojf'),
            'type' => \Elementor\Controls_Manager::COLOR,
            'selectors' => array('{{WRAPPER}} .ojf-campo' => 'color: {{VALUE}};'),
        ));

        $this->add_group_control(\Elementor\Group_Control_Typography::get_type(), array(
            'name' => 'tipografia',
            'selector' => '{{WRAPPER}} .ojf-campo',
        ));

        $this->add_responsive_control('alinhamento', array(
            'label' => esc_html__('Alinhamento', 'odontojf'),
            'type' => \Elementor\Controls_Manager::CHOOSE,
            'options' => array(
                'left'   => array('title' => esc_html__('Esquerda', 'odontojf'), 'icon' => 'eicon-text-align-left'),
                'center' => array('title' => esc_html__('Centro', 'odontojf'),   'icon' => 'eicon-text-align-center'),
                'right'  => array('title' => esc_html__('Direita', 'odontojf'),  'icon' => 'eicon-text-align-right'),
            ),
            'selectors' => array('{{WRAPPER}} .ojf-campo' => 'text-align: {{VALUE}};'),
        ));

        $this->end_controls_section();
    }

    protected function render() {
        if (!function_exists('wc_get_product')) return;

        $s = $this->get_settings_for_display();
        $product = wc_get_product(get_the_ID());
        if (!$product) return;

        $value = $this->value($product, $s);

        if ($value === '' || $value === null) {
            if ($s['ocultar_vazio'] === 'yes') return;
            $value = '';
        }

        $allowed = array('div', 'span', 'p', 'h2', 'h3', 'h4', 'h5', 'strong');
        $tag = in_array($s['tag'], $allowed, true) ? $s['tag'] : 'div';

        $html = $s['prefixo'] . $value . $s['sufixo'];

        if ($s['link'] === 'yes') {
            $html = '<a href="' . esc_url(get_permalink($product->get_id())) . '">' . $html . '</a>';
        }

        printf('<%1$s class="ojf-campo ojf-campo--%2$s">%3$s</%1$s>',
            $tag, esc_attr($s['campo']), wp_kses_post($html));
    }

    /**
     * Valor do campo. Já escapado — o preço volta como HTML do WooCommerce.
     */
    private function value($product, $s) {
        if ($s['campo'] === 'titulo_produto') {
            return esc_html($product->get_name());
        }

        // Produto simples: o "campo da variação" é o do próprio produto.
        if (!$product->is_type('variable')) {
            return $this->fromProduct($product, $s['campo']);
        }

        $ids = $this->variationIds($product, $s);
        if (empty($ids)) return '';

        $partes = array();
        foreach ($ids as $vid) {
            $parte = $this->fromVariation((int) $vid, $s['campo'], $product);
            if ($parte !== '') $partes[] = $parte;
        }
        if (empty($partes)) return '';

        $sep = isset($s['separador']) && $s['separador'] !== '' ? $s['separador'] : ' · ';
        return implode(esc_html($sep), array_unique($partes));
    }

    /** @return int[] */
    private function variationIds($product, $s) {
        $children = $product->get_children();
        if (empty($children)) return array();

        if ($s['origem'] === 'todas') {
            $max = isset($s['maximo']) ? max(1, (int) $s['maximo']) : 4;
            return array_slice($children, 0, $max);
        }

        if ($s['origem'] === 'barata') {
            $melhor = 0; $menor = null;
            foreach ($children as $vid) {
                $preco = get_post_meta($vid, '_price', true);
                if ($preco === '' || $preco === null) continue;
                if ($menor === null || (float) $preco < $menor) {
                    $menor = (float) $preco; $melhor = (int) $vid;
                }
            }
            return $melhor ? array($melhor) : array((int) $children[0]);
        }

        return array((int) $children[0]);
    }

    private function fromVariation($vid, $campo, $product) {
        if ($campo === 'titulo_variacao') {
            // Meta primeiro: é o título próprio vindo da origem. O nome montado
            // pelo Woo ("Pai - N°150") é só o fallback.
            $own = get_post_meta($vid, self::META_TITLE, true);
            if (is_string($own) && trim($own) !== '') return esc_html(trim($own));

            $v = wc_get_product($vid);
            if (!$v) return '';
            $sufixo = wc_get_formatted_variation($v, true, false, false);
            return esc_html($sufixo !== '' ? $product->get_name() . ' - ' . $sufixo : $product->get_name());
        }

        $v = wc_get_product($vid);
        return $v ? $this->fromProduct($v, $campo) : '';
    }

    private function fromProduct($p, $campo) {
        switch ($campo) {
            case 'preco':
                return $p->get_price_html();
            case 'sku':
                return esc_html((string) $p->get_sku());
            case 'peso':
                $w = $p->get_weight();
                return ($w !== '' && $w !== null) ? esc_html(wc_format_weight($w)) : '';
            case 'dimensoes':
                $d = wc_format_dimensions($p->get_dimensions(false));
                return ($d && $d !== 'N/A') ? esc_html($d) : '';
            case 'titulo_variacao':
                return esc_html($p->get_name());
        }
        return '';
    }
}
