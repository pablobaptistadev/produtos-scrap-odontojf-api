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
        '.ojf-atc--sem-preco .woocommerce-variation-price{display:none!important}'
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

    \$.post(CFG.ajaxUrl, { product_id: productId, quantity: qty })
      .done(function (res) {
        if (res && res.error && res.product_url) { window.location = res.product_url; return; }

        \$(document.body).trigger('added_to_cart', [res && res.fragments, res && res.cart_hash, \$btn]);
        state(\$btn, CHECK + '<span>' + (\$box.data('done') || 'Adicionado ao carrinho') + '</span>');

        setTimeout(function () {
          \$btn.removeClass('ojf-atc-busy').removeAttr('aria-busy').css({ minWidth: '', minHeight: '' }).html(original);
        }, 2200);
      })
      .fail(function () {
        // Nunca deixa o cliente preso num botão girando: devolve o botão e
        // manda o formulário do jeito tradicional.
        \$btn.removeClass('ojf-atc-busy').removeAttr('aria-busy').css({ minWidth: '', minHeight: '' }).html(original);
        \$form.off('submit').trigger('submit');
      });
  });
})(window.jQuery);
JS;

    wp_add_inline_script('wc-add-to-cart-variation', $js);
}
