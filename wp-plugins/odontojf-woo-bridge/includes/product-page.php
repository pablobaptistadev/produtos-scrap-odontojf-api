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

/**
 * [preco_info] — preço do produto, seguindo a variação escolhida.
 *
 * Simples  → preço do próprio produto.
 * Variável → faixa do pai (ou o texto de `placeholder`) até o cliente escolher;
 *            a partir daí o preço da variação, trocado pelo mesmo JS que cuida
 *            de título/SKU/peso/dimensões — o data-ojf-field="price" basta.
 *
 * Atributos:
 *   placeholder="Selecione uma opção"  texto enquanto nada está selecionado.
 *                                      Útil quando alguma variação vem sem
 *                                      preço e a faixa do pai começa em R$ 0,00.
 *   classe="minha-classe"              classe extra no wrapper.
 */
add_shortcode('preco_info', 'ojf_pp_price_info');
function ojf_pp_price_info($atts) {
    $atts = shortcode_atts(['placeholder' => '', 'classe' => ''], $atts);

    $product = $GLOBALS['product'] ?? null;
    if (!$product instanceof WC_Product) {
        $product = wc_get_product(get_the_ID());
    }
    if (!$product instanceof WC_Product) return '';

    $is_variable = $product->is_type('variable');
    $initial = ($is_variable && $atts['placeholder'] !== '')
        ? '<span class="ojf-preco-placeholder">' . esc_html($atts['placeholder']) . '</span>'
        : $product->get_price_html();

    $classes = 'ojf-preco' . ($is_variable ? ' ojf-preco--variavel' : '') . ' ojf-pp-live';
    if ($atts['classe'] !== '') $classes .= ' ' . sanitize_html_class($atts['classe']);

    // O data-ojf-field só entra em variável: em produto simples não há o que
    // trocar, e marcar à toa faria o JS tentar animar um elemento estático.
    $field = $is_variable ? ' data-ojf-field="price"' : '';

    return '<span class="' . esc_attr($classes) . '"' . $field . '>' . $initial . '</span>';
}

/**
 * [titulo_info] — título do produto, seguindo a variação escolhida.
 *
 * Simples  → nome do produto.
 * Variável → nome do pai (ou `placeholder`) até escolher; depois o título
 *            próprio da variação, que o Bridge grava no meta
 *            `_odontojf_variation_title` e injeta no JSON da página.
 *
 * Por que não dá para usar um campo dinâmico apontando para o meta: na PDP o
 * objeto consultado é o produto PAI, então o campo leria o meta dele (vazio).
 * O valor da variação só existe no cliente, depois da seleção.
 *
 * Atributos:
 *   placeholder="Selecione uma opção"  texto enquanto nada está selecionado
 *   tag="h2"                           elemento a renderizar (padrão: span)
 *   classe="minha-classe"              classe extra
 */
add_shortcode('titulo_info', 'ojf_pp_title_info');
function ojf_pp_title_info($atts) {
    $atts = shortcode_atts(['placeholder' => '', 'tag' => 'span', 'classe' => ''], $atts);

    $product = $GLOBALS['product'] ?? null;
    if (!$product instanceof WC_Product) {
        $product = wc_get_product(get_the_ID());
    }
    if (!$product instanceof WC_Product) return '';

    $allowed = ['span', 'div', 'p', 'h1', 'h2', 'h3', 'h4', 'strong'];
    $tag = in_array(strtolower($atts['tag']), $allowed, true) ? strtolower($atts['tag']) : 'span';

    $is_variable = $product->is_type('variable');
    $initial = ($is_variable && $atts['placeholder'] !== '')
        ? '<span class="ojf-titulo-placeholder">' . esc_html($atts['placeholder']) . '</span>'
        : esc_html($product->get_name());

    $classes = 'ojf-titulo' . ($is_variable ? ' ojf-titulo--variavel' : '') . ' ojf-pp-live';
    if ($atts['classe'] !== '') $classes .= ' ' . sanitize_html_class($atts['classe']);

    $field = $is_variable ? ' data-ojf-field="title"' : '';

    return '<' . $tag . ' class="' . esc_attr($classes) . '"' . $field . '>' . $initial . '</' . $tag . '>';
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
      . '.ojf-preco{display:inline-block}'
      . '.ojf-preco-placeholder{opacity:.6;font-weight:400}'
      . '.ojf-titulo{display:inline-block}'
      . '.ojf-titulo-placeholder{opacity:.6;font-weight:400}'
        // "Ler mais": a descrição da variação tem ~2.000 caracteres e empurrava
        // o botão de compra para fora da tela. Colapsa com degradê e expande.
      . '.ojf-clamp{position:relative;overflow:hidden;transition:max-height .45s cubic-bezier(.4,0,.2,1)}'
      . '.ojf-clamp::after{content:"";position:absolute;left:0;right:0;bottom:0;height:110px;'
      .   'pointer-events:none;opacity:1;transition:opacity .35s ease;'
      .   'background:linear-gradient(to bottom,var(--ojf-fade-0,rgba(255,255,255,0)) 0%,'
      .   'var(--ojf-fade-1,rgba(255,255,255,.9)) 62%,var(--ojf-fade-2,#fff) 100%)}'
      . '.ojf-clamp.is-open::after{opacity:0}'
      . '.ojf-more{order:4;display:flex;justify-content:center;margin:2px 0 10px}'
        // O tema estiliza todo <button> como botão de compra (preenchido, largura
        // total, caixa alta). Este é um controle de leitura, não uma ação de
        // compra — não pode competir com "Adicionar ao carrinho". Daí a classe
        // própria e os !important: é o estilo do tema que precisa ser vencido.
      . '.ojf-more .ojf-more-btn{appearance:none!important;background:none!important;'
      .   'background-color:transparent!important;background-image:none!important;border:0!important;'
      .   'box-shadow:none!important;border-radius:0!important;width:auto!important;min-width:0!important;'
      .   'height:auto!important;min-height:0!important;margin:0!important;padding:6px 4px!important;'
      .   'font:inherit!important;font-size:.86em!important;font-weight:500!important;'
      .   'letter-spacing:0!important;text-transform:none!important;line-height:1.2!important;'
      .   'color:#6b7280!important;text-decoration:none!important;cursor:pointer;'
      .   'display:inline-flex!important;align-items:center;justify-content:center;gap:6px;'
      .   'transition:color .2s ease}'
      . '.ojf-more .ojf-more-btn:hover{color:#111827!important;text-decoration:underline!important}'
      . '.ojf-more .ojf-more-btn:focus-visible{outline:2px solid currentColor;outline-offset:3px}'
      . '.ojf-more svg{width:13px;height:13px;opacity:.75;transition:transform .35s cubic-bezier(.4,0,.2,1)}'
      . '.ojf-more.is-open svg{transform:rotate(180deg)}'
      . '@media (prefers-reduced-motion:reduce){.ojf-pp-live,.ojf-clamp,.ojf-more svg{transition:none}'
      .   '.ojf-pp-out{opacity:1;transform:none}}';

    if (wp_style_is('woocommerce-general', 'registered')) {
        wp_add_inline_style('woocommerce-general', $css);
    } else {
        add_action('wp_head', function () use ($css) { echo '<style id="ojf-product-page">' . $css . '</style>'; });
    }

    $clamp = (int) apply_filters('ojf_pp_description_max_height', 220);
    // Nem sempre o que a página mostra como "SKU" é o _sku: nesta loja um campo
    // do JetEngine exibe o código do ERP. Mandamos todos os códigos que
    // representam o pai para o JS conseguir achar e trocar qualquer um deles.
    $parent_codes = array($parent_sku);
    if ($current instanceof WC_Product) {
        foreach (array('_ojf_erp_code', '_odontojf_sku', '_odontojf_barcode') as $meta) {
            $v = $current->get_meta($meta, true);
            if (is_string($v) && trim($v) !== '') $parent_codes[] = trim($v);
        }
    }
    $parent_codes = array_values(array_unique(array_filter($parent_codes)));

    $cfg = wp_json_encode([
        'parentSku'   => $parent_sku,
        'parentCodes' => $parent_codes,
        'dimUnit'    => get_option('woocommerce_dimension_unit'),
        'weightUnit' => get_option('woocommerce_weight_unit'),
        // Formato de preço da loja, para montar o valor quando o Woo não manda
        // price_html (ver ojf_pp_price_html no JS).
        'price' => [
            'sym'     => get_woocommerce_currency_symbol(),
            'dec'     => wc_get_price_decimals(),
            'decSep'  => wc_get_price_decimal_separator(),
            'milSep'  => wc_get_price_thousand_separator(),
            'format'  => get_woocommerce_price_format(),
        ],
    ]);

    $js = <<<JS
(function (\$) {
  'use strict';
  if (!\$) return;
  var CFG = {$cfg};

  \$(function () {
    // A página tem MAIS DE UMA form.variations_form (o template do tema e a do
    // nosso widget). Prender em .first() ligava tudo na form errada — era por
    // isso que SKU, "Ler mais" e URL não reagiam enquanto galeria e preço, que
    // não dependem deste script, funcionavam. Os eventos passam a ser
    // delegados: valem para qualquer form, inclusive as criadas depois.
    if (!\$('form.variations_form').length) return;

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
    var codigos = (CFG.parentCodes || [CFG.parentSku]).filter(function (c) {
      return c && String(c).length > 1;
    });
    if (codigos.length) {
      \$('.jet-listing-dynamic-field__content, .woocommerce-product-attributes-item__value, .sku, .sku_wrapper').each(function () {
        var \$n = \$(this);
        if (\$n.children().length > 2) return;
        var texto = \$n.text(), achado = null;
        for (var i = 0; i < codigos.length; i++) {
          if (texto.indexOf(codigos[i]) !== -1) { achado = codigos[i]; break; }
        }
        if (!achado) return;
        \$n.addClass('ojf-pp-live');
        skuNodes.push({ el: \$n, html: \$n.html(), code: achado });
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

    /* ---- "Ler mais" na descrição da variação ---- */

    var CLAMP_PX = {$clamp};

    // O degradê precisa terminar na cor REAL do fundo, senão sobra uma faixa
    // branca sobre fundo colorido. Sobe a árvore até achar um fundo opaco e
    // devolve os três stops já montados — nada de cirurgia em string, porque
    // getComputedStyle devolve ora "rgb(...)" ora "rgba(...)".
    function fadeStops(el) {
      var rgb = [255, 255, 255];
      for (var n = el.parentNode; n && n.nodeType === 1; n = n.parentNode) {
        var parts = (window.getComputedStyle(n).backgroundColor || '').match(/[\\d.]+/g);
        if (!parts || parts.length < 3) continue;
        var alpha = parts.length > 3 ? parseFloat(parts[3]) : 1;
        if (alpha < 0.95) continue; // translúcido: o que vale é o que está atrás
        rgb = [parts[0], parts[1], parts[2]];
        break;
      }
      var base = rgb.join(',');
      return {
        clear: 'rgba(' + base + ',0)',
        soft:  'rgba(' + base + ',0.9)',
        solid: 'rgba(' + base + ',1)'
      };
    }

    function clampDescription(\$form) {
      var el = \$form.find('.woocommerce-variation-description').first()[0];
      if (!el) return;
      \$('.ojf-more').remove();
      el.classList.remove('ojf-clamp', 'is-open');
      el.style.maxHeight = '';

      // Só colapsa se realmente sobrar conteúdo — senão o botão seria ruído.
      if (el.scrollHeight <= CLAMP_PX + 60) return;

      var stops = fadeStops(el);
      el.style.setProperty('--ojf-fade-0', stops.clear);
      el.style.setProperty('--ojf-fade-1', stops.soft);
      el.style.setProperty('--ojf-fade-2', stops.solid);

      el.classList.add('ojf-clamp');
      el.style.maxHeight = CLAMP_PX + 'px';

      var chev = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" '
               + 'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>';
      var \$more = \$('<div class="ojf-more"><button type="button" class="ojf-more-btn" aria-expanded="false">'
                    + '<span>Ler mais</span>' + chev + '</button></div>');
      \$(el).after(\$more);

      \$more.on('click', 'button', function () {
        var open = el.classList.toggle('is-open');
        \$more.toggleClass('is-open', open);
        \$(this).attr('aria-expanded', open ? 'true' : 'false')
               .find('span').text(open ? 'Ler menos' : 'Ler mais');

        if (open) {
          // Anima até a altura real e depois solta, para o conteúdo poder
          // crescer (imagem que carrega, etc.) sem ficar cortado.
          el.style.maxHeight = el.scrollHeight + 'px';
          \$(el).one('transitionend', function () {
            if (el.classList.contains('is-open')) el.style.maxHeight = 'none';
          });
        } else {
          el.style.maxHeight = el.scrollHeight + 'px';
          void el.offsetHeight; // força reflow para a transição de volta rodar
          el.style.maxHeight = CLAMP_PX + 'px';
          \$('html, body').animate({ scrollTop: \$(el).offset().top - 120 }, 260);
        }
      });
    }

    /* ---- preço ---- */

    // O Woo só manda `price_html` por variação quando o menor e o maior preço do
    // produto DIFEREM — e ele compara já arredondado pelas casas decimais da loja.
    // Com a loja em 1 casa, 4,97 e 4,98 viram o mesmo número: o Woo conclui que o
    // produto tem preço único, manda price_html vazio, e a linha de preço da
    // variação fica em branco. Aqui montamos o valor a partir do display_price,
    // que vem sempre.
    function fmtPreco(v) {
      var p = CFG.price || {};
      var n = Number(v);
      if (!isFinite(n)) return '';
      var casas = (p.dec === 0 || p.dec) ? p.dec : 2;
      var partes = n.toFixed(casas).split('.');
      partes[0] = partes[0].replace(/\B(?=(\d{3})+(?!\d))/g, p.milSep || '');
      var num = partes.join(p.decSep || ',');
      var sym = '<span class="woocommerce-Price-currencySymbol">' + (p.sym || '') + '</span>';
      var fmt = p.format || '%1\$s\u00a0%2\$s';
      var txt = fmt.replace('%1\$s', sym).replace('%2\$s', num);
      return '<span class="woocommerce-Price-amount amount"><bdi>' + txt + '</bdi></span>';
    }

    function precoDaVariacao(variation) {
      if (variation.price_html) return variation.price_html;
      if (variation.display_price === null || variation.display_price === undefined) return '';
      var atual = fmtPreco(variation.display_price);
      var reg   = variation.display_regular_price;
      if (reg !== null && reg !== undefined && Number(reg) > Number(variation.display_price)) {
        return '<del aria-hidden="true">' + fmtPreco(reg) + '</del> <ins>' + atual + '</ins>';
      }
      return atual;
    }

    // Quando o Woo manda price_html vazio E a página não mostra preço em nenhum
    // outro lugar do produto, a linha de preço da variação fica em branco. Nesse
    // caso — e só nesse — preenchemos o bloco nativo. Se já existe preço visível
    // (widget de preço do tema, [preco_info]), não encostamos: preço duplicado é
    // pior que preço no lugar de sempre.
    // \$.trim saiu no jQuery 4; o WordPress ainda manda o 3, mas não custa.
    function texto(\$el) { return String(\$el.text() || '').trim(); }

    function precoVisivelNoProduto(\$form) {
      if (pick('price').length) return true;
      var \$escopo = \$form.closest('.product, .elementor-widget-wrap, body');
      var achou = false;
      \$escopo.find('p.price, span.price, .woocommerce-Price-amount').each(function () {
        var \$e = \$(this);
        if (\$e.closest('.elementor-menu-cart, .products, .elementor-loop-container, .woocommerce-variation-price, .related, .up-sells').length) return;
        if (texto(\$e) !== '' && \$e.is(':visible')) { achou = true; return false; }
      });
      return achou;
    }

    function garantePreco(\$form, variation) {
      var \$alvo = \$form.find('.woocommerce-variation-price').first();
      if (!\$alvo.length) return;
      if (texto(\$alvo) !== '') return;              // o Woo já preencheu
      if (precoVisivelNoProduto(\$form)) return;      // já tem preço em outro lugar
      var html = precoDaVariacao(variation);
      if (html) \$alvo.html('<span class="price">' + html + '</span>');
    }

    /* ---- eventos ---- */

    // show_variation dispara DEPOIS de o core reescrever o .single_variation,
    // então o título injetado aqui nunca duplica.
    \$(document).on('show_variation.ojfPP', 'form.variations_form', function (event, variation) {
      if (!variation) return;
      var \$form = \$(this);

      var wrap = \$form.find('.woocommerce-variation.single_variation').first();
      if (wrap.length && variation.ojf_variation_title) {
        wrap.prepend('<div class="ojf-variation-title">' + esc(variation.ojf_variation_title) + '</div>');
      }

      clampDescription(\$form);

      animate(function () {
        if (variation.ojf_variation_title) setHtml('title', esc(variation.ojf_variation_title));
        var precoHtml = precoDaVariacao(variation);
        if (precoHtml)                     setHtml('price', precoHtml);
        garantePreco(\$form, variation);
        if (variation.weight_html)         setHtml('weight', variation.weight_html);
        if (variation.dimensions_html)     setHtml('dims', variation.dimensions_html);

        var d = variation.dimensions || {};
        if (d.height) setHtml('height', esc(d.height + ' ' + CFG.dimUnit));
        if (d.width)  setHtml('width',  esc(d.width  + ' ' + CFG.dimUnit));
        if (d.length) setHtml('length', esc(d.length + ' ' + CFG.dimUnit));

        if (variation.sku) {
          setHtml('sku', esc(variation.sku));
          skuNodes.forEach(function (n) {
            n.el.html(n.html.split(n.code).join(esc(variation.sku)));
          });
        }
      });
    });

    /* ---- URL segue a seleção ---- */

    // Sem isto a barra de endereço fica com a variação que veio no load (ou
    // vazia) enquanto o cliente navega por outra — link copiado abre o item
    // errado. replaceState não empilha histórico, então o botão Voltar continua
    // saindo da página como o usuário espera.
    var urlOriginal = window.location.href;

    function syncUrl(variation) {
      if (!window.history || !window.history.replaceState) return;
      try {
        var u = new URL(window.location.href);
        Object.keys(variation.attributes || {}).forEach(function (k) {
          var v = variation.attributes[k];
          if (v === '' || v == null) u.searchParams.delete(k);
          else u.searchParams.set(k, v);
        });
        window.history.replaceState(window.history.state, '', u.toString());
      } catch (e) { /* URL não suportada: sem URL bonita, mas a página segue */ }
    }

    function clearUrl() {
      if (!window.history || !window.history.replaceState) return;
      try {
        var u = new URL(window.location.href);
        Array.prototype.slice.call(u.searchParams.keys())
          .filter(function (k) { return k.indexOf('attribute_') === 0; })
          .forEach(function (k) { u.searchParams.delete(k); });
        window.history.replaceState(window.history.state, '', u.toString());
      } catch (e) {}
    }

    // Título do documento acompanha, para a aba e o link compartilhado.
    var docTitleOriginal = document.title;
    function syncDocTitle(t) {
      if (!t) return;
      var tail = docTitleOriginal.indexOf(' – ') > -1
        ? docTitleOriginal.slice(docTitleOriginal.indexOf(' – '))
        : (docTitleOriginal.indexOf(' - ') > -1 ? docTitleOriginal.slice(docTitleOriginal.indexOf(' - ')) : '');
      document.title = t + tail;
    }

    \$(document).on('show_variation.ojfPPUrl', 'form.variations_form', function (event, variation) {
      if (!variation) return;
      syncUrl(variation);
      syncDocTitle(variation.ojf_variation_title);
    });

    \$(document).on('reset_data.ojfPP', 'form.variations_form', function () {
      animate(restore);
      clearUrl();
      document.title = docTitleOriginal;
    });
  });
})(window.jQuery);
JS;

    wp_add_inline_script('wc-add-to-cart-variation', $js);
}
