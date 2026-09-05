<?php
/**
 * Módulo da página de produto (>= 1.0.40).
 *
 * Faz os dados exibidos na PDP seguirem a VARIAÇÃO selecionada, não o pai:
 * título, SKU, preço, peso e dimensões. Tudo com transição suave.
 *
 * Substitui o snippet [producto_info] que vivia solto no Code Snippets. Mesmo
 * nome de shortcode e mesmos atributos/rótulos, então nenhuma página precisa
 * ser editada — mas agora as células são identificadas por data-ojf-field e o
 * JS troca o conteúdo quando o cliente escolhe a variação.
 *
 * Por que o WooCommerce não faz isso sozinho: a tabela de "informação
 * adicional" é renderizada no servidor a partir do produto PAI, e o
 * wc-add-to-cart-variation.js só atualiza o bloco .single_variation
 * (descrição, preço e disponibilidade). Peso, dimensões, SKU e título ficam
 * congelados no pai.
 *
 * Os dados vêm do próprio JSON que o WooCommerce já entrega em
 * data-product_variations — get_available_variation() inclui sku, price_html,
 * weight, weight_html, dimensions e dimensions_html — mais o
 * ojf_variation_title que o Bridge injeta (includes/variation-gallery.php).
 * Zero requisição extra.
 */

if (!defined('ABSPATH')) exit;

/* ── shortcode ────────────────────────────────────────────────────────────── */

/**
 * Assume o tag [producto_info]. O remove_shortcode antes garante que a nossa
 * versão vence caso o snippet antigo ainda esteja ativo (a ordem de carga
 * entre plugin e Code Snippets não é garantida).
 */
add_action('init', 'ojf_pp_register_shortcode', 99);
function ojf_pp_register_shortcode() {
    remove_shortcode('producto_info');
    add_shortcode('producto_info', 'ojf_pp_product_info');
    add_shortcode('ojf_produto_info', 'ojf_pp_product_info'); // alias
}

function ojf_pp_product_info($atts) {
    $atts = shortcode_atts([
        'categorias'  => 'no',
        'tags'        => 'no',
        'altura'      => 'no',
        'anchura'     => 'no',
        'profundidad' => 'no',
        'sku'         => 'no',
        'peso'        => 'no',   // novo
        'titulo'      => 'no',   // novo
        'preco'       => 'no',   // novo
    ], $atts);

    $product = $GLOBALS['product'] ?? null;
    if (!$product instanceof WC_Product) {
        $product = wc_get_product(get_the_ID());
    }
    if (!$product instanceof WC_Product) {
        return 'Este shortcode deve ser usado numa página de produto';
    }

    $unit = get_option('woocommerce_dimension_unit');
    $rows = [];

    if ($atts['titulo'] === 'yes') {
        $rows[] = ['Título:', 'title', esc_html($product->get_name())];
    }
    if ($atts['preco'] === 'yes') {
        $rows[] = ['Preço:', 'price', $product->get_price_html()];
    }
    if ($atts['categorias'] === 'yes') {
        $terms = get_the_terms($product->get_id(), 'product_cat');
        if ($terms && !is_wp_error($terms)) {
            $links = [];
            foreach ($terms as $t) {
                $links[] = '<a href="' . esc_url(get_term_link($t)) . '">' . esc_html($t->name) . '</a>';
            }
            $rows[] = ['Categorías:', '', implode(', ', $links)];
        }
    }
    if ($atts['tags'] === 'yes') {
        $terms = get_the_terms($product->get_id(), 'product_tag');
        if ($terms && !is_wp_error($terms)) {
            $links = [];
            foreach ($terms as $t) {
                $links[] = '<a href="' . esc_url(get_term_link($t)) . '">' . esc_html($t->name) . '</a>';
            }
            $rows[] = ['Etiquetas:', '', implode(', ', $links)];
        }
    }
    if ($atts['altura'] === 'yes' && $product->get_height()) {
        $rows[] = ['Altura:', 'height', esc_html($product->get_height() . ' ' . $unit)];
    }
    if ($atts['anchura'] === 'yes' && $product->get_width()) {
        $rows[] = ['Anchura:', 'width', esc_html($product->get_width() . ' ' . $unit)];
    }
    if ($atts['profundidad'] === 'yes' && $product->get_length()) {
        $rows[] = ['Profundidad:', 'length', esc_html($product->get_length() . ' ' . $unit)];
    }
    if ($atts['peso'] === 'yes' && $product->get_weight()) {
        $rows[] = ['Peso:', 'weight', esc_html($product->get_weight() . ' ' . get_option('woocommerce_weight_unit'))];
    }
    if ($atts['sku'] === 'yes' && $product->get_sku()) {
        $rows[] = ['SKU:', 'sku', esc_html($product->get_sku())];
    }

    ob_start();
    echo '<div class="ojf-pp">';
    // Tabela nativa do WooCommerce (peso, dimensões e atributos), preservada.
    do_action('woocommerce_product_additional_information', $product);

    if ($rows) {
        echo '<h4 class="ojf-pp-heading">Información Adicional</h4>';
        echo '<table class="woocommerce-product-attributes shop_attributes">';
        foreach ($rows as $r) {
            list($label, $field, $value) = $r;
            $attr = $field !== '' ? ' data-ojf-field="' . esc_attr($field) . '"' : '';
            echo '<tr class="woocommerce-product-attributes-item">'
               . '<th class="woocommerce-product-attributes-item__label">' . esc_html($label) . '</th>'
               . '<td class="woocommerce-product-attributes-item__value"' . $attr . '>' . $value . '</td>'
               . '</tr>';
        }
        echo '</table>';
    }
    echo '</div>';
    return ob_get_clean();
}

/* ── front: os campos seguem a variação ───────────────────────────────────── */

add_action('wp_enqueue_scripts', 'ojf_pp_assets', 30);
function ojf_pp_assets() {
    if (!function_exists('is_product') || !is_product()) return;
    if (!wp_script_is('wc-add-to-cart-variation', 'registered')) return;

    $current    = wc_get_product(get_queried_object_id());
    $parent_sku = ($current instanceof WC_Product) ? (string) $current->get_sku() : '';

    $css =
        // Reordena o bloco da variação: o template do core imprime
        // descrição -> preço -> disponibilidade, e com 2.000 caracteres de
        // descrição o preço acabava lá embaixo. Flex order não sobrescreve
        // template e sobrevive ao re-render a cada seleção.
        '.woocommerce-variation.single_variation{display:flex;flex-direction:column}'
      . '.ojf-variation-title{order:0;font-weight:600;line-height:1.3;margin:0 0 8px}'
      . '.woocommerce-variation-price{order:1}'
      . '.woocommerce-variation-availability{order:2}'
      . '.woocommerce-variation-description{order:3;margin-top:12px}'
        // Transição dos campos que trocam.
      . '.ojf-pp-live{transition:opacity .2s cubic-bezier(.4,0,.2,1),transform .2s cubic-bezier(.4,0,.2,1);will-change:opacity,transform}'
      . '.ojf-pp-out{opacity:0;transform:translateY(-3px)}'
      . '@media (prefers-reduced-motion:reduce){.ojf-pp-live{transition:none}.ojf-pp-out{opacity:1;transform:none}}';

    if (wp_style_is('woocommerce-general', 'registered')) {
        wp_add_inline_style('woocommerce-general', $css);
    } else {
        add_action('wp_head', function () use ($css) { echo '<style id="ojf-product-page">' . $css . '</style>'; });
    }

    $cfg = wp_json_encode([
        'parentSku'  => $parent_sku,
        'dimUnit'    => get_option('woocommerce_dimension_unit'),
        'weightUnit' => get_option('woocommerce_weight_unit'),
    ]);

    $js = <<<JS
(function (\$) {
  'use strict';
  if (!\$) return;
  var CFG = {$cfg};

  \$(function () {
    var \$form = \$('.variations_form').first();
    if (!\$form.length) return;

    /* ---- alvos ---- */

    // H1 do tema (Elementor não usa .product_title).
    var \$h1 = \$('h1.elementor-heading-title, h1.product_title').first();
    var \$h1Text = \$h1.find('a').length ? \$h1.find('a').first() : \$h1;

    // Células do nosso shortcode + as linhas nativas do WooCommerce.
    function pick(field, extra) {
      var sel = '[data-ojf-field="' + field + '"]';
      if (extra) sel += ',' + extra;
      return \$(sel);
    }
    var targets = {
      title:  \$h1Text.add(pick('title')),
      price:  pick('price'),
      sku:    pick('sku', '.woocommerce-product-attributes-item--sku .woocommerce-product-attributes-item__value'),
      weight: pick('weight', '.woocommerce-product-attributes-item--weight .woocommerce-product-attributes-item__value'),
      dims:   pick('', '.woocommerce-product-attributes-item--dimensions .woocommerce-product-attributes-item__value'),
      height: pick('height'),
      width:  pick('width'),
      length: pick('length')
    };

    // Guarda o estado original de tudo que pode mudar, para o reset_data.
    var original = {};
    Object.keys(targets).forEach(function (k) {
      original[k] = targets[k].map(function () { return \$(this).html(); }).get();
      targets[k].addClass('ojf-pp-live');
    });

    // O SKU do pai aparece em lugares que não controlamos (campo dinâmico do
    // JetEngine, tabela de atributos). Em vez de adivinhar a marcação, guarda
    // o HTML e troca só a ocorrência do código — rótulo e formatação sobrevivem.
    var skuNodes = [];
    if (CFG.parentSku) {
      \$('.jet-listing-dynamic-field__content, .woocommerce-product-attributes-item__value, .sku, .sku_wrapper').each(function () {
        var \$n = \$(this);
        if (\$n.children().length > 2) return;
        if (\$n.text().indexOf(CFG.parentSku) === -1) return;
        \$n.addClass('ojf-pp-live');
        skuNodes.push({ el: \$n, html: \$n.html() });
      });
    }

    /* ---- transição ---- */

    function animate(apply) {
      var \$all = \$('.ojf-pp-live');
      \$all.addClass('ojf-pp-out');
      setTimeout(function () {
        apply();
        \$all.removeClass('ojf-pp-out');
      }, 190);
    }

    function setHtml(key, value) {
      if (value == null || value === '') return;
      targets[key].each(function () { \$(this).html(value); });
    }

    function restore() {
      Object.keys(targets).forEach(function (k) {
        targets[k].each(function (i) {
          if (original[k][i] !== undefined) \$(this).html(original[k][i]);
        });
      });
      skuNodes.forEach(function (n) { n.el.html(n.html); });
    }

    function esc(t) {
      return \$('<div>').text(t == null ? '' : String(t)).html();
    }

    /* ---- eventos ---- */

    // show_variation dispara DEPOIS de o core reescrever o .single_variation,
    // então o título injetado aqui nunca duplica.
    \$form.on('show_variation.ojfPP', function (event, variation) {
      if (!variation) return;

      var wrap = \$form.find('.woocommerce-variation.single_variation').first();
      if (wrap.length && variation.ojf_variation_title) {
        wrap.prepend('<div class="ojf-variation-title">' + esc(variation.ojf_variation_title) + '</div>');
      }

      animate(function () {
        if (variation.ojf_variation_title) setHtml('title', esc(variation.ojf_variation_title));
        if (variation.price_html)          setHtml('price', variation.price_html);
        if (variation.weight_html)         setHtml('weight', variation.weight_html);
        if (variation.dimensions_html)     setHtml('dims', variation.dimensions_html);

        var d = variation.dimensions || {};
        if (d.height) setHtml('height', esc(d.height + ' ' + CFG.dimUnit));
        if (d.width)  setHtml('width',  esc(d.width  + ' ' + CFG.dimUnit));
        if (d.length) setHtml('length', esc(d.length + ' ' + CFG.dimUnit));

        if (variation.sku) {
          setHtml('sku', esc(variation.sku));
          skuNodes.forEach(function (n) {
            n.el.html(n.html.split(CFG.parentSku).join(esc(variation.sku)));
          });
        }
      });
    });

    \$form.on('reset_data.ojfPP', function () { animate(restore); });
  });
})(window.jQuery);
JS;

    wp_add_inline_script('wc-add-to-cart-variation', $js);
}
