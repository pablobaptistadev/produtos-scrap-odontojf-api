<?php
/**
 * Registro do widget Elementor + assets do carrinho AJAX (>= 1.0.46).
 *
 * O plugin é self-contained (nenhum arquivo .js/.css solto), então CSS e JS
 * vão inline pendurados em handles que o WooCommerce já enfileira.
 */

if (!defined('ABSPATH')) exit;

add_action('elementor/widgets/register', 'ojf_el_register_widgets');
function ojf_el_register_widgets($widgets_manager) {
    if (!function_exists('WC')) return;
    require_once OJF_BRIDGE_DIR . 'includes/elementor/class-ojf-add-to-cart-widget.php';
    $widgets_manager->register(new OJF_Add_To_Cart_Widget());

    require_once OJF_BRIDGE_DIR . 'includes/elementor/class-ojf-field-widget.php';
    $widgets_manager->register(new OJF_Field_Widget());
}

/**
 * Assets do widget. Rodam na página de produto e também na pré-visualização do
 * Elementor, onde is_product() é falso.
 */
add_action('wp_enqueue_scripts', 'ojf_atc_assets', 31);
function ojf_atc_assets() {
    $is_preview = class_exists('\Elementor\Plugin')
        && \Elementor\Plugin::$instance->preview->is_preview_mode();

    if (!$is_preview && (!function_exists('is_product') || !is_product())) return;
    if (!wp_script_is('wc-add-to-cart-variation', 'registered')) return;

    $css =
        // O bloco de variação do WooCommerce repete preço e descrição; a página
        // já mostra ambos. Escondido por opção do widget, nunca por padrão global.
        // Layout base do botão: o tema não previa ícone dentro dele, então o
        // conteúdo é centralizado por flex aqui. É a única regra de aparência
        // que entra sem o lojista pedir — o resto vem dos controles do widget.
        '.ojf-atc form.cart .single_add_to_cart_button{display:inline-flex!important;'
      .   'align-items:center;justify-content:center;gap:10px;line-height:1.2;text-align:center}'
      . '.ojf-atc .ojf-atc-ico{display:inline-flex;align-items:center;flex:0 0 auto}'
      . '.ojf-atc .ojf-atc-ico svg{width:18px;height:18px;display:block;fill:currentColor}'
      . '.ojf-atc .ojf-atc-ico i{line-height:1}'
      . '.ojf-atc .ojf-atc-label{display:inline-block}'
        // Campo de quantidade próprio. O input nativo continua no formulário —
        // só perde as setinhas do navegador e ganha os botões ao lado.
      . '.ojf-atc .ojf-qty{display:inline-flex!important;align-items:stretch;overflow:hidden;'
      .   'border:1px solid #dedede;border-radius:4px;background:#fff;flex:0 0 auto}'
      . '.ojf-atc .ojf-qty input.qty{-moz-appearance:textfield;appearance:textfield;width:48px;'
      .   'border:0!important;outline:0!important;box-shadow:none!important;background:transparent!important;'
      .   'text-align:center;padding:0 2px!important;margin:0!important;min-height:0!important;'
      .   'height:auto!important;font:inherit;line-height:1.2;align-self:stretch}'
      . '.ojf-atc .ojf-qty input.qty::-webkit-outer-spin-button,'
      .   '.ojf-atc .ojf-qty input.qty::-webkit-inner-spin-button{-webkit-appearance:none;margin:0}'
      . '.ojf-atc .ojf-qty-btn{appearance:none;border:0;background:transparent;cursor:pointer;'
      .   'width:38px;padding:0;margin:0;display:inline-flex;align-items:center;justify-content:center;'
      .   'color:#374151;transition:background .15s ease,opacity .15s ease;-webkit-tap-highlight-color:transparent}'
      . '.ojf-atc .ojf-qty-btn:hover:not(:disabled){background:rgba(0,0,0,.05)}'
      . '.ojf-atc .ojf-qty-btn:disabled{opacity:.3;cursor:default}'
      . '.ojf-atc .ojf-qty-btn:focus-visible{outline:2px solid currentColor;outline-offset:-2px}'
      . '.ojf-atc .ojf-qty-btn svg{width:14px;height:14px;fill:none;stroke:currentColor;stroke-width:2.2;'
      .   'stroke-linecap:round;display:block}'
      . '.ojf-atc .ojf-qty label{position:absolute!important;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0)}'
        // Estoque 1: o Woo já não deixa escolher quantidade, então o seletor sai
        // da linha e o botão fica com 100% naturalmente.
      . '.ojf-atc .ojf-qty--fixa{display:none!important}'
        // O tema flutua o .quantity à esquerda; num container flex isso atrapalha.
      . '.ojf-atc form.cart .quantity{float:none!important;margin:0!important}'
        // Modo "quantidade ao lado do botão": a linha precisa ser flex de fato,
        // senão as proporções não valem. gap 0 por padrão — espaçamento é
        // controle do widget.
      . '.ojf-atc--lado form.cart .woocommerce-variation-add-to-cart,'
      .   '.ojf-atc--lado form.cart:not(.variations_form){display:flex!important;'
      .   'align-items:stretch;flex-wrap:nowrap;gap:0}'
        // Estado de sucesso: só o NOSSO ícone. O Woo e o tema injetam .added e
        // um indicador próprio no botão, e apareciam dois.
      . '.ojf-atc .single_add_to_cart_button.ojf-atc-done > *:not(.ojf-atc-state){display:none!important}'
      . '.ojf-atc .single_add_to_cart_button.ojf-atc-done::after,'
      .   '.ojf-atc .single_add_to_cart_button.ojf-atc-done::before{display:none!important;content:none!important}'
      . '.ojf-atc--sem-preco .woocommerce-variation-price{display:none!important}'
      . '.ojf-atc--sem-descricao .woocommerce-variation-description{display:none!important}'
        // Estado de carregando: o botão NÃO muda de cor nem de tamanho — só o
        // conteúdo é trocado, com a largura travada antes da troca para a linha
        // não "pular" enquanto o carrinho atualiza.
      . '.ojf-atc .single_add_to_cart_button{position:relative;transition:opacity .2s ease}'
      . '.ojf-atc .single_add_to_cart_button.ojf-atc-busy{pointer-events:none;opacity:.92}'
      . '.ojf-atc-state{display:inline-flex;align-items:center;justify-content:center;gap:9px;'
      .   'animation:ojf-atc-in .22s cubic-bezier(.4,0,.2,1) both}'
      . '@keyframes ojf-atc-in{from{opacity:0;transform:translateY(3px)}to{opacity:1;transform:none}}'
      . '.ojf-atc-spin{width:15px;height:15px;flex:0 0 15px;border-radius:50%;'
      .   'border:2px solid currentColor;border-right-color:transparent;opacity:.85;'
      .   'animation:ojf-atc-spin .62s linear infinite}'
      . '@keyframes ojf-atc-spin{to{transform:rotate(360deg)}}'
      . '.ojf-atc-check{width:15px;height:15px;flex:0 0 15px}'
      . '.ojf-atc-check path{stroke:currentColor;stroke-width:2.6;fill:none;stroke-linecap:round;'
      .   'stroke-linejoin:round;stroke-dasharray:20;stroke-dashoffset:20;'
      .   'animation:ojf-atc-draw .32s .04s cubic-bezier(.4,0,.2,1) forwards}'
      . '@keyframes ojf-atc-draw{to{stroke-dashoffset:0}}'
      . '@media (prefers-reduced-motion:reduce){.ojf-atc-state,.ojf-atc-check path{animation:none}'
      .   '.ojf-atc-check path{stroke-dashoffset:0}.ojf-atc-spin{animation-duration:1.6s}}';

    if (wp_style_is('woocommerce-general', 'registered')) {
        wp_add_inline_style('woocommerce-general', $css);
    } else {
        add_action('wp_head', function () use ($css) { echo '<style id="ojf-atc">' . $css . '</style>'; });
    }

    $cfg = wp_json_encode(array(
        'ajaxUrl'  => function_exists('WC_AJAX::get_endpoint')
            ? WC_AJAX::get_endpoint('add_to_cart')
            : add_query_arg('wc-ajax', 'add_to_cart', home_url('/')),
        'cartUrl'  => wc_get_cart_url(),
    ));

    $js = <<<JS
(function (\$) {
  'use strict';
  if (!\$) return;
  var CFG = {$cfg};

  var SPIN  = '<span class="ojf-atc-spin" aria-hidden="true"></span>';
  var CHECK = '<svg class="ojf-atc-check" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 12.5l5 5L20 6.5"/></svg>';

  // WC_AJAX::add_to_cart aceita o id da VARIAÇÃO em product_id e resolve o pai
  // e os atributos sozinho — por isso não montamos o array de atributos aqui.
  function productIdFor(\$form) {
    var v = parseInt(\$form.find('input[name="variation_id"]').val(), 10);
    if (v > 0) return v;
    var b = parseInt(\$form.find('[name="add-to-cart"]').val(), 10);
    return b > 0 ? b : 0;
  }

  function state(\$btn, html) {
    \$btn.html('<span class="ojf-atc-state">' + html + '</span>');
  }

  /* ---- pré-seleção da variação ---- */

  // Sem seleção o produto abre mostrando a faixa do pai (que aqui começa em
  // R$ 0,00 quando alguma variação está sem preço) e exige um clique a mais.
  // O que veio na URL sempre vence: link compartilhado não pode ser
  // sobrescrito.
  function preSelecionar() {
    \$('.ojf-atc[data-preselect]').each(function () {
      var \$box = \$(this);
      var \$form = \$box.find('form.variations_form').first();
      if (!\$form.length || \$form.data('ojfPreSel')) return;

      var attrs;
      try { attrs = JSON.parse(\$box.attr('data-preselect')); } catch (e) { return; }
      if (!attrs) return;

      var jaSelecionado = false;
      \$form.find('select[name^="attribute_"]').each(function () {
        if (\$(this).val()) jaSelecionado = true;
      });
      if (jaSelecionado) { \$form.data('ojfPreSel', 1); return; }

      \$form.data('ojfPreSel', 1);

      Object.keys(attrs).forEach(function (nome) {
        var valor = attrs[nome];
        if (!valor) return;

        // Prefere clicar na swatch do CommerceKit: é ela que mantém o estado
        // visual. Mexer só no <select> deixaria o botão certo sem destaque.
        var \$sw = \$form.find('.cgkit-attribute-swatches[data-attribute="' + nome + '"] button[data-attribute-value="' + valor.replace(/"/g, '\\"') + '"]').first();
        if (\$sw.length) { \$sw.trigger('click'); return; }

        \$form.find('select[name="' + nome + '"]').val(valor).trigger('change');
      });
    });
  }

  // Depois do wc_variation_form montar as swatches. Duas tentativas: em lojas
  // com muitos scripts o CommerceKit às vezes só termina depois do ready.
  \$(function () { setTimeout(preSelecionar, 250); setTimeout(preSelecionar, 1200); });

  /* ---- quantidade: − e + ---- */

  function qtyState(\$wrap) {
    var \$i = \$wrap.find('input.qty');
    if (!\$i.length) return;
    var val = parseFloat(\$i.val());
    var min = parseFloat(\$i.attr('min'));
    var max = parseFloat(\$i.attr('max'));
    \$wrap.find('.ojf-qty-minus').prop('disabled', !isNaN(min) && !(val > min));
    \$wrap.find('.ojf-qty-plus').prop('disabled', !isNaN(max) && !(val < max));
  }

  \$(document).on('click', '.ojf-atc .ojf-qty-btn', function () {
    var \$b = \$(this);
    var \$wrap = \$b.closest('.ojf-qty');
    var \$i = \$wrap.find('input.qty');
    if (!\$i.length || \$i.prop('disabled')) return;

    // Respeita min/max/step do input nativo — é o WooCommerce que decide os
    // limites (estoque, venda individual, múltiplos).
    var step = parseFloat(\$i.attr('step')) || 1;
    var min  = parseFloat(\$i.attr('min'));
    var max  = parseFloat(\$i.attr('max'));
    var val  = parseFloat(\$i.val());
    if (isNaN(val)) val = isNaN(min) ? 1 : min;

    val += \$b.hasClass('ojf-qty-plus') ? step : -step;
    if (!isNaN(min) && val < min) val = min;
    if (!isNaN(max) && val > max) val = max;

    var casas = (String(step).split('.')[1] || '').length;
    \$i.val(casas ? val.toFixed(casas) : val).trigger('change');
    qtyState(\$wrap);
  });

  \$(document).on('change input', '.ojf-atc .ojf-qty input.qty', function () {
    qtyState(\$(this).closest('.ojf-qty'));
  });

  // O max muda quando a variação muda (estoque diferente por variação).
  \$(document).on('found_variation reset_data', '.ojf-atc form.cart', function () {
    setTimeout(function () { \$('.ojf-atc .ojf-qty').each(function () { qtyState(\$(this)); }); }, 0);
  });

  \$(function () { \$('.ojf-atc .ojf-qty').each(function () { qtyState(\$(this)); }); });

  \$(document).on('submit', '.ojf-atc--ajax form.cart', function (e) {
    var \$form = \$(this);
    var \$box  = \$form.closest('.ojf-atc');
    var \$btn  = \$form.find('.single_add_to_cart_button').first();

    if (!\$btn.length || \$btn.hasClass('disabled') || \$btn.is(':disabled')) return; // deixa o Woo avisar
    var productId = productIdFor(\$form);
    if (!productId) return; // variação não escolhida: submit normal mostra o aviso

    e.preventDefault();

    // Trava a caixa ANTES de trocar o conteúdo: sem isso o botão encolhe no
    // meio do clique e a linha inteira pula.
    var original = \$btn.html();
    \$btn.css({ minWidth: \$btn.outerWidth() + 'px', minHeight: \$btn.outerHeight() + 'px' });
    \$btn.addClass('ojf-atc-busy').attr('aria-busy', 'true');
    state(\$btn, SPIN + '<span>' + (\$box.data('loading') || 'Atualizando carrinho...') + '</span>');

    var qty = parseFloat(\$form.find('input.qty').val());
    if (!(qty > 0)) qty = 1;

    function restaura() {
      \$btn.removeClass('ojf-atc-busy ojf-atc-done added').removeAttr('aria-busy')
          .css({ minWidth: '', minHeight: '' }).html(original);
    }

    // Rede de segurança: aconteça o que acontecer, o botão volta. Sem isto um
    // erro de terceiro deixava o cliente olhando o spinner para sempre.
    var watchdog = setTimeout(restaura, 15000);

    \$.ajax({ url: CFG.ajaxUrl, type: 'POST', timeout: 20000,
              data: { product_id: productId, quantity: qty } })
      .done(function (res) {
        clearTimeout(watchdog);

        if (res && res.error && res.product_url) { window.location = res.product_url; return; }

        // O estado de sucesso vem ANTES de disparar added_to_cart: esse evento
        // é ouvido pelo tema e por outros plugins, e um deles estourando aqui
        // abortava o resto deste callback — o botão ficava girando sem fim.
        // .added vem do Woo/tema com indicador próprio; com o nosso check dava
        // dois ícones. Sai enquanto o estado for nosso.
        \$btn.removeClass('added').addClass('ojf-atc-done');
        state(\$btn, CHECK + '<span>' + (\$box.data('done') || 'Carrinho atualizado') + '</span>');
        setTimeout(restaura, 5000);

        try {
          \$(document.body).trigger('added_to_cart', [res && res.fragments, res && res.cart_hash, \$btn]);
        } catch (err) {
          if (window.console && console.warn) console.warn('[ojf] added_to_cart falhou num listener de terceiro', err);
        }
      })
      .fail(function () {
        // Nunca deixa o cliente preso: devolve o botão e manda o formulário do
        // jeito tradicional.
        clearTimeout(watchdog);
        restaura();
        \$form.off('submit').trigger('submit');
      });
  });
})(window.jQuery);
JS;

    wp_add_inline_script('wc-add-to-cart-variation', $js);
}
