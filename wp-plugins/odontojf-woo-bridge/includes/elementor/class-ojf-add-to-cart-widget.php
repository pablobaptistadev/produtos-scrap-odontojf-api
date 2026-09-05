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

    /** Ícone do carrinho do cliente (Font Awesome "opencart"). */
    const ICONE_PADRAO = '<svg class="ojf-atc-ico-svg" viewBox="0 0 640 512" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false"><path d="M423.3 440.7c0 25.3-20.3 45.6-45.6 45.6s-45.8-20.3-45.8-45.6 20.6-45.8 45.8-45.8c25.4 0 45.6 20.5 45.6 45.8zm-253.9-45.8c-25.3 0-45.6 20.6-45.6 45.8s20.3 45.6 45.6 45.6 45.8-20.3 45.8-45.6-20.5-45.8-45.8-45.8zm291.7-270C158.9 124.9 81.9 112.1 0 25.7c34.4 51.7 53.3 148.9 373.1 144.2 333.3-5 130 86.1 70.8 188.9 186.7-166.7 319.4-233.9 17.2-233.9z"/></svg>';

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

        /* ── Botão: conteúdo ── */
        $this->start_controls_section('secao_botao', array(
            'label' => esc_html__('Botão', 'odontojf'),
        ));

        $this->add_control('texto_botao', array(
            'label' => esc_html__('Texto', 'odontojf'),
            'type' => \Elementor\Controls_Manager::TEXT,
            'placeholder' => esc_html__('Deixe vazio para manter o texto do WooCommerce', 'odontojf'),
        ));

        $this->add_control('qty_custom', array(
            'label' => esc_html__('Campo de quantidade próprio', 'odontojf'),
            'description' => esc_html__('Troca o input do WooCommerce por um com − e + controláveis. O campo nativo continua por baixo, então o carrinho recebe o mesmo dado.', 'odontojf'),
            'type' => \Elementor\Controls_Manager::SWITCHER,
            'default' => 'yes',
        ));

        $this->add_control('mostrar_icone', array(
            'label' => esc_html__('Ícone', 'odontojf'),
            'type' => \Elementor\Controls_Manager::SWITCHER,
            'default' => 'yes',
        ));

        $this->add_control('icone', array(
            'label' => esc_html__('Trocar o ícone', 'odontojf'),
            'type' => \Elementor\Controls_Manager::ICONS,
            'description' => esc_html__('Vazio usa o ícone de carrinho padrão.', 'odontojf'),
            'condition' => array('mostrar_icone' => 'yes'),
        ));

        $this->add_control('posicao_icone', array(
            'label' => esc_html__('Posição do ícone', 'odontojf'),
            'type' => \Elementor\Controls_Manager::SELECT,
            'default' => 'antes',
            'options' => array(
                'antes'  => esc_html__('Antes do texto', 'odontojf'),
                'depois' => esc_html__('Depois do texto', 'odontojf'),
            ),
            'condition' => array('mostrar_icone' => 'yes'),
        ));

        $this->end_controls_section();

        /* ── Botão: estilo ── */
        $this->start_controls_section('secao_estilo_botao', array(
            'label' => esc_html__('Botão', 'odontojf'),
            'tab' => \Elementor\Controls_Manager::TAB_STYLE,
        ));

        // O botão é o nativo do WooCommerce e o tema o estiliza com regras bem
        // específicas; por isso os seletores descem até .ojf-atc form.cart e o
        // valor sai com !important — sem isso o controle do Elementor não pega.
        $btn = '{{WRAPPER}} .ojf-atc form.cart .single_add_to_cart_button';

        $this->add_responsive_control('largura_botao', array(
            'label' => esc_html__('Largura', 'odontojf'),
            'type' => \Elementor\Controls_Manager::SLIDER,
            'size_units' => array('%', 'px'),
            'range' => array('%' => array('min' => 10, 'max' => 100), 'px' => array('min' => 80, 'max' => 800)),
            'selectors' => array($btn => 'width: {{SIZE}}{{UNIT}} !important; max-width: 100% !important;'),
        ));

        $this->add_responsive_control('alinhamento_botao', array(
            'label' => esc_html__('Alinhamento', 'odontojf'),
            'type' => \Elementor\Controls_Manager::CHOOSE,
            'options' => array(
                'flex-start' => array('title' => esc_html__('Esquerda', 'odontojf'), 'icon' => 'eicon-text-align-left'),
                'center'     => array('title' => esc_html__('Centro', 'odontojf'),   'icon' => 'eicon-text-align-center'),
                'flex-end'   => array('title' => esc_html__('Direita', 'odontojf'),  'icon' => 'eicon-text-align-right'),
                'stretch'    => array('title' => esc_html__('Largura total', 'odontojf'), 'icon' => 'eicon-h-align-stretch'),
            ),
            'default' => 'stretch',
            // Em produto VARIÁVEL o form.cart embrulha também a tabela de
            // variações; virar flex ali quebraria o seletor. A linha que
            // interessa é .woocommerce-variation-add-to-cart (qtd + botão).
            // Em produto simples, essa linha é o próprio form.
            'selectors' => array(
                '{{WRAPPER}} .ojf-atc form.cart .woocommerce-variation-add-to-cart, {{WRAPPER}} .ojf-atc form.cart:not(.variations_form)'
                    => 'display:flex;flex-wrap:wrap;align-items:center;gap:10px;justify-content:{{VALUE}};',
            ),
        ));

        $this->add_group_control(\Elementor\Group_Control_Typography::get_type(), array(
            'name' => 'tipografia_botao',
            'selector' => $btn,
        ));

        $this->add_responsive_control('padding_botao', array(
            'label' => esc_html__('Espaçamento interno', 'odontojf'),
            'type' => \Elementor\Controls_Manager::DIMENSIONS,
            'size_units' => array('px', 'em', '%'),
            'selectors' => array($btn => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}} !important;'),
        ));

        $this->add_responsive_control('raio_botao', array(
            'label' => esc_html__('Arredondamento', 'odontojf'),
            'type' => \Elementor\Controls_Manager::DIMENSIONS,
            'size_units' => array('px', '%'),
            'selectors' => array($btn => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}} !important;'),
        ));

        $this->add_responsive_control('espaco_icone', array(
            'label' => esc_html__('Espaço do ícone', 'odontojf'),
            'type' => \Elementor\Controls_Manager::SLIDER,
            'range' => array('px' => array('min' => 0, 'max' => 40)),
            'default' => array('size' => 10, 'unit' => 'px'),
            'selectors' => array($btn => 'gap: {{SIZE}}{{UNIT}} !important;'),
            'condition' => array('mostrar_icone' => 'yes'),
        ));

        $this->add_responsive_control('tamanho_icone', array(
            'label' => esc_html__('Tamanho do ícone', 'odontojf'),
            'type' => \Elementor\Controls_Manager::SLIDER,
            'range' => array('px' => array('min' => 8, 'max' => 64)),
            'default' => array('size' => 18, 'unit' => 'px'),
            'selectors' => array(
                '{{WRAPPER}} .ojf-atc .ojf-atc-ico svg' => 'width: {{SIZE}}{{UNIT}} !important; height: {{SIZE}}{{UNIT}} !important;',
                '{{WRAPPER}} .ojf-atc .ojf-atc-ico i'   => 'font-size: {{SIZE}}{{UNIT}} !important;',
            ),
            'condition' => array('mostrar_icone' => 'yes'),
        ));

        $this->start_controls_tabs('abas_botao');

        $this->start_controls_tab('aba_normal', array('label' => esc_html__('Normal', 'odontojf')));

        $this->add_control('cor_texto', array(
            'label' => esc_html__('Cor do texto', 'odontojf'),
            'type' => \Elementor\Controls_Manager::COLOR,
            'selectors' => array($btn => 'color: {{VALUE}} !important; fill: {{VALUE}} !important;'),
        ));

        $this->add_group_control(\Elementor\Group_Control_Background::get_type(), array(
            'name' => 'fundo_botao',
            'types' => array('classic', 'gradient'),
            'selector' => $btn,
            'fields_options' => array(
                'background' => array('default' => 'classic'),
                'color' => array('selectors' => array($btn => 'background-color: {{VALUE}} !important;')),
            ),
        ));

        $this->add_group_control(\Elementor\Group_Control_Border::get_type(), array(
            'name' => 'borda_botao',
            'selector' => $btn,
        ));

        $this->add_group_control(\Elementor\Group_Control_Box_Shadow::get_type(), array(
            'name' => 'sombra_botao',
            'selector' => $btn,
        ));

        $this->end_controls_tab();

        $this->start_controls_tab('aba_hover', array('label' => esc_html__('Hover', 'odontojf')));

        $this->add_control('cor_texto_hover', array(
            'label' => esc_html__('Cor do texto', 'odontojf'),
            'type' => \Elementor\Controls_Manager::COLOR,
            'selectors' => array($btn . ':hover' => 'color: {{VALUE}} !important; fill: {{VALUE}} !important;'),
        ));

        $this->add_control('fundo_hover', array(
            'label' => esc_html__('Fundo', 'odontojf'),
            'type' => \Elementor\Controls_Manager::COLOR,
            'selectors' => array($btn . ':hover' => 'background-color: {{VALUE}} !important;'),
        ));

        $this->add_control('borda_hover', array(
            'label' => esc_html__('Cor da borda', 'odontojf'),
            'type' => \Elementor\Controls_Manager::COLOR,
            'selectors' => array($btn . ':hover' => 'border-color: {{VALUE}} !important;'),
        ));

        $this->add_group_control(\Elementor\Group_Control_Box_Shadow::get_type(), array(
            'name' => 'sombra_hover',
            'selector' => $btn . ':hover',
        ));

        $this->end_controls_tab();
        $this->end_controls_tabs();

        $this->end_controls_section();

        /* ── Quantidade ── */
        $this->start_controls_section('secao_estilo_qty', array(
            'label' => esc_html__('Quantidade', 'odontojf'),
            'tab' => \Elementor\Controls_Manager::TAB_STYLE,
        ));

        $qty  = '{{WRAPPER}} .ojf-atc .ojf-qty';
        $qin  = $qty . ' input.qty';
        $qbtn = $qty . ' .ojf-qty-btn';

        $this->add_control('qty_igualar', array(
            'label' => esc_html__('Igualar altura ao botão', 'odontojf'),
            'type' => \Elementor\Controls_Manager::SWITCHER,
            'default' => 'yes',
            'selectors' => array(
                '{{WRAPPER}} .ojf-atc form.cart .woocommerce-variation-add-to-cart, {{WRAPPER}} .ojf-atc form.cart:not(.variations_form)'
                    => 'align-items: stretch !important;',
                $qty => 'height: auto !important;',
            ),
        ));

        $this->add_responsive_control('qty_largura', array(
            'label' => esc_html__('Largura do número', 'odontojf'),
            'type' => \Elementor\Controls_Manager::SLIDER,
            'range' => array('px' => array('min' => 24, 'max' => 120)),
            'default' => array('size' => 48, 'unit' => 'px'),
            'selectors' => array($qin => 'width: {{SIZE}}{{UNIT}} !important;'),
        ));

        $this->add_responsive_control('qty_botao_largura', array(
            'label' => esc_html__('Largura dos botões', 'odontojf'),
            'type' => \Elementor\Controls_Manager::SLIDER,
            'range' => array('px' => array('min' => 24, 'max' => 80)),
            'default' => array('size' => 38, 'unit' => 'px'),
            'selectors' => array($qbtn => 'width: {{SIZE}}{{UNIT}} !important;'),
        ));

        $this->add_responsive_control('qty_icone', array(
            'label' => esc_html__('Tamanho dos sinais', 'odontojf'),
            'type' => \Elementor\Controls_Manager::SLIDER,
            'range' => array('px' => array('min' => 8, 'max' => 28)),
            'default' => array('size' => 14, 'unit' => 'px'),
            'selectors' => array($qbtn . ' svg' => 'width: {{SIZE}}{{UNIT}} !important; height: {{SIZE}}{{UNIT}} !important;'),
        ));

        $this->add_responsive_control('qty_raio', array(
            'label' => esc_html__('Arredondamento', 'odontojf'),
            'type' => \Elementor\Controls_Manager::DIMENSIONS,
            'size_units' => array('px', '%'),
            'selectors' => array($qty => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}} !important;'),
        ));

        $this->add_group_control(\Elementor\Group_Control_Typography::get_type(), array(
            'name' => 'qty_tipografia',
            'selector' => $qin,
        ));

        $this->add_control('qty_cor', array(
            'label' => esc_html__('Cor do número', 'odontojf'),
            'type' => \Elementor\Controls_Manager::COLOR,
            'selectors' => array($qin => 'color: {{VALUE}} !important;'),
        ));

        $this->add_control('qty_fundo', array(
            'label' => esc_html__('Fundo', 'odontojf'),
            'type' => \Elementor\Controls_Manager::COLOR,
            'selectors' => array($qty => 'background: {{VALUE}} !important;'),
        ));

        $this->add_group_control(\Elementor\Group_Control_Border::get_type(), array(
            'name' => 'qty_borda',
            'selector' => $qty,
        ));

        $this->add_control('qty_btn_cor', array(
            'label' => esc_html__('Cor dos sinais', 'odontojf'),
            'type' => \Elementor\Controls_Manager::COLOR,
            'selectors' => array($qbtn => 'color: {{VALUE}} !important;'),
        ));

        $this->add_control('qty_btn_hover', array(
            'label' => esc_html__('Fundo dos sinais no hover', 'odontojf'),
            'type' => \Elementor\Controls_Manager::COLOR,
            'selectors' => array($qbtn . ':hover:not(:disabled)' => 'background: {{VALUE}} !important;'),
        ));

        $this->end_controls_section();

        /* ── Variações (swatches do CommerceKit) ── */
        $this->start_controls_section('secao_estilo_swatches', array(
            'label' => esc_html__('Seletor de variação', 'odontojf'),
            'tab' => \Elementor\Controls_Manager::TAB_STYLE,
        ));

        // As swatches são renderizadas pelo CommerceKit DENTRO do form.cart,
        // portanto dentro deste widget — por isso {{WRAPPER}} alcança. Como o
        // CSS do CommerceKit é específico, os valores saem com !important.
        $sw     = '{{WRAPPER}} .ojf-atc .cgkit-attribute-swatch.cgkit-button button';
        $sw_sel = $sw . '.cgkit-swatch-selected';

        $this->add_control('corrigir_swatch', array(
            'label' => esc_html__('Corrigir o tamanho dos botões', 'odontojf'),
            'type' => \Elementor\Controls_Manager::SWITCHER,
            'default' => '',
            'description' => esc_html__('O CommerceKit fixa min-width 47px, min-height 43px e line-height 43px, o que corta rótulos maiores (N°18R). Ligado, o botão passa a se ajustar ao texto.', 'odontojf'),
            'selectors' => array(
                $sw => 'display:inline-flex!important;align-items:center;justify-content:center;'
                     . 'min-width:0!important;min-height:0!important;height:auto!important;'
                     . 'line-height:1.2!important;padding:9px 13px!important;white-space:nowrap;',
            ),
        ));

        $this->add_responsive_control('espaco_swatch', array(
            'label' => esc_html__('Espaço entre eles', 'odontojf'),
            'type' => \Elementor\Controls_Manager::SLIDER,
            'range' => array('px' => array('min' => 0, 'max' => 30)),
            'selectors' => array(
                '{{WRAPPER}} .ojf-atc .cgkit-attribute-swatches' => 'display:flex;flex-wrap:wrap;gap:{{SIZE}}{{UNIT}};',
            ),
        ));

        $this->add_group_control(\Elementor\Group_Control_Typography::get_type(), array(
            'name' => 'tipografia_swatch',
            'selector' => $sw,
        ));

        $this->add_responsive_control('padding_swatch', array(
            'label' => esc_html__('Espaçamento interno', 'odontojf'),
            'type' => \Elementor\Controls_Manager::DIMENSIONS,
            'size_units' => array('px', 'em'),
            'selectors' => array($sw => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}} !important;'),
        ));

        $this->add_responsive_control('raio_swatch', array(
            'label' => esc_html__('Arredondamento', 'odontojf'),
            'type' => \Elementor\Controls_Manager::DIMENSIONS,
            'size_units' => array('px', '%'),
            'selectors' => array($sw => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}} !important;'),
        ));

        $this->add_control('titulo_swatch_cor', array(
            'label' => esc_html__('Cor do rótulo (ex.: "variacao:")', 'odontojf'),
            'type' => \Elementor\Controls_Manager::COLOR,
            'selectors' => array('{{WRAPPER}} .ojf-atc .cgkit-swatch-title' => 'color: {{VALUE}} !important;'),
        ));

        $this->start_controls_tabs('abas_swatch');

        $this->start_controls_tab('aba_sw_normal', array('label' => esc_html__('Normal', 'odontojf')));
        $this->add_control('sw_cor', array(
            'label' => esc_html__('Texto', 'odontojf'),
            'type' => \Elementor\Controls_Manager::COLOR,
            'selectors' => array($sw => 'color: {{VALUE}} !important;'),
        ));
        $this->add_control('sw_fundo', array(
            'label' => esc_html__('Fundo', 'odontojf'),
            'type' => \Elementor\Controls_Manager::COLOR,
            'selectors' => array($sw => 'background: {{VALUE}} !important;'),
        ));
        $this->add_control('sw_borda', array(
            'label' => esc_html__('Borda', 'odontojf'),
            'type' => \Elementor\Controls_Manager::COLOR,
            'selectors' => array($sw => 'border-color: {{VALUE}} !important;'),
        ));
        $this->end_controls_tab();

        $this->start_controls_tab('aba_sw_hover', array('label' => esc_html__('Hover', 'odontojf')));
        $this->add_control('sw_cor_hover', array(
            'label' => esc_html__('Texto', 'odontojf'),
            'type' => \Elementor\Controls_Manager::COLOR,
            'selectors' => array($sw . ':hover' => 'color: {{VALUE}} !important;'),
        ));
        $this->add_control('sw_fundo_hover', array(
            'label' => esc_html__('Fundo', 'odontojf'),
            'type' => \Elementor\Controls_Manager::COLOR,
            'selectors' => array($sw . ':hover' => 'background: {{VALUE}} !important;'),
        ));
        $this->add_control('sw_borda_hover', array(
            'label' => esc_html__('Borda', 'odontojf'),
            'type' => \Elementor\Controls_Manager::COLOR,
            'selectors' => array($sw . ':hover' => 'border-color: {{VALUE}} !important;'),
        ));
        $this->end_controls_tab();

        $this->start_controls_tab('aba_sw_sel', array('label' => esc_html__('Selecionado', 'odontojf')));
        $this->add_control('sw_cor_sel', array(
            'label' => esc_html__('Texto', 'odontojf'),
            'type' => \Elementor\Controls_Manager::COLOR,
            'selectors' => array($sw_sel => 'color: {{VALUE}} !important;'),
        ));
        $this->add_control('sw_fundo_sel', array(
            'label' => esc_html__('Fundo', 'odontojf'),
            'type' => \Elementor\Controls_Manager::COLOR,
            'selectors' => array($sw_sel => 'background: {{VALUE}} !important;'),
        ));
        $this->add_control('sw_borda_sel', array(
            'label' => esc_html__('Borda', 'odontojf'),
            'type' => \Elementor\Controls_Manager::COLOR,
            'selectors' => array($sw_sel => 'border-color: {{VALUE}} !important;'),
        ));
        $this->end_controls_tab();

        $this->end_controls_tabs();
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

        // Captura a saída do template do Woo para poder mexer no botão. O
        // filtro woocommerce_product_single_add_to_cart_text não serve: o
        // template passa o texto por esc_html(), então HTML (o ícone) não
        // sobrevive por ali.
        ob_start();
        woocommerce_template_single_add_to_cart();
        $markup = ob_get_clean();
        $markup = $this->decorateQuantity($markup, $settings);
        echo $this->decorateButton($markup, $settings); // phpcs:ignore WordPress.Security.EscapeOutput

        echo '</div>';

        $GLOBALS['product'] = $previous;
    }

    /**
     * Troca o texto do botão e envolve o conteúdo em spans, para o ícone e o
     * rótulo poderem ser alinhados e estilizados de forma independente.
     */
    private function decorateButton($html, $s) {
        $icone = $s['mostrar_icone'] === 'yes' ? $this->iconHtml($s) : '';
        $texto = trim((string) $s['texto_botao']);

        if ($icone === '' && $texto === '') {
            return $html;
        }

        $padrao = '#(<button[^>]*\bsingle_add_to_cart_button\b[^>]*>)(.*?)(</button>)#is';

        return preg_replace_callback($padrao, function ($m) use ($icone, $texto) {
            $rotulo = $texto !== '' ? esc_html($texto) : $m[2];
            $conteudo = '<span class="ojf-atc-label">' . $rotulo . '</span>';

            if ($icone !== '') {
                $ico = '<span class="ojf-atc-ico">' . $icone . '</span>';
                $conteudo = $this->posicao === 'depois' ? $conteudo . $ico : $ico . $conteudo;
            }

            return $m[1] . $conteudo . $m[3];
        }, $html, 1);
    }

    /**
     * Envolve o input nativo de quantidade com os botões − e +.
     *
     * O input do WooCommerce continua ali, com o mesmo name="quantity" e as
     * mesmas regras de min/max/step — os botões só mexem no value e disparam
     * change. Substituí-lo por um campo próprio quebraria validação de estoque,
     * venda individual e qualquer plugin que escute esse input.
     */
    private function decorateQuantity($html, $s) {
        if ($s['qty_custom'] !== 'yes') return $html;

        $html = preg_replace_callback(
            '#<div([^>]*\bclass="[^"]*\bquantity\b[^"]*"[^>]*)>#i',
            function ($m) {
                return '<div' . preg_replace('#class="#', 'class="ojf-qty ', $m[1], 1) . '>';
            },
            $html, 1
        );

        $menos = '<button type="button" class="ojf-qty-btn ojf-qty-minus" aria-label="' . esc_attr__('Diminuir', 'odontojf') . '">'
               . '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14"/></svg></button>';
        $mais  = '<button type="button" class="ojf-qty-btn ojf-qty-plus" aria-label="' . esc_attr__('Aumentar', 'odontojf') . '">'
               . '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg></button>';

        return preg_replace_callback(
            '#<input[^>]*\bclass="[^"]*\bqty\b[^"]*"[^>]*>#i',
            function ($m) use ($menos, $mais) { return $menos . $m[0] . $mais; },
            $html, 1
        );
    }

    /** @var string */
    private $posicao = 'antes';

    private function iconHtml($s) {
        $this->posicao = isset($s['posicao_icone']) ? $s['posicao_icone'] : 'antes';

        // Ícone escolhido no Elementor tem prioridade; sem escolha, o do cliente.
        if (!empty($s['icone']['value'])) {
            ob_start();
            \Elementor\Icons_Manager::render_icon($s['icone'], array('aria-hidden' => 'true'));
            $render = trim(ob_get_clean());
            if ($render !== '') return $render;
        }

        return self::ICONE_PADRAO;
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
