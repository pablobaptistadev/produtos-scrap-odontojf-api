<?php
/**
 * Galeria por variação (>= 1.0.36).
 *
 * Na origem cada "variação" é um produto com página, título, descrição e
 * GALERIA próprios. O WooCommerce só guarda UMA imagem por variação
 * (`set_image_id`), então o Bridge grava as demais em `_odontojf_variation_gallery`
 * (CSV de attachment IDs, na ordem da origem) — ver ojf_sync_variations().
 *
 * Este arquivo faz as duas pontas que faltam:
 *   • FRONT  — troca a galeria do produto pela da variação selecionada.
 *   • ADMIN  — deixa o lojista ver/curar essa galeria na aba Variações.
 *
 * Por que meta próprio e não o CommerceKit: a Attributes Gallery dele trabalha
 * sobre atributos GLOBAIS (`pa_*`), e o Bridge cria atributos MANUAIS
 * (`set_id(0)`). Migrar 1.769 produtos para taxonomias globais só para isso não
 * se justifica.
 *
 * ⚠️ PILAR B: todo anexo referenciado aqui precisa aparecer em
 * ojf_collect_product_attachment_ids() (product-handler.php), senão a varredura
 * de órfãos apaga o anexo no próximo update e o hook delete_attachment leva o
 * objeto no R2 junto. A função ojf_get_variation_gallery_ids() já está ligada lá.
 */

if (!defined('ABSPATH')) exit;

/**
 * Todas as imagens da variação na ordem de exibição: a thumbnail nativa
 * (set_image_id) primeiro, depois as extras de _odontojf_variation_gallery.
 *
 * @param int $variation_id
 * @return int[] attachment IDs, sem repetição
 */
function ojf_variation_gallery_image_ids($variation_id) {
    $variation_id = (int) $variation_id;
    if ($variation_id <= 0) return [];

    $ids = [];
    $thumb = (int) get_post_thumbnail_id($variation_id);
    if ($thumb) $ids[] = $thumb;

    if (function_exists('ojf_get_variation_gallery_ids')) {
        foreach (ojf_get_variation_gallery_ids($variation_id) as $g) $ids[] = (int) $g;
    }

    return array_values(array_unique(array_filter($ids)));
}

/* ── COMMERCEKIT (renderização nativa) ────────────────────────────────────── */

/**
 * Espelha a galeria das variações no meta que o CommerceKit já lê.
 *
 * A loja usa o CommerceKit (2.4.2) como galeria da PDP: o markup nativo do
 * WooCommerce fica estacionado dentro de <template class="wc-product-gallery-
 * default-template"> e quem desenha é o swiper `#commercegurus-pdp-gallery`.
 * O módulo "Attributes Gallery" dele já está ligado (commercekit_as.cgkit_attr_gal
 * = 1) e JÁ funciona com nossos atributos MANUAIS — cgkit_attr_names na loja é
 * ["attribute_variacao"].
 *
 * Formato (lido em includes/pdp-attributes-gallery-swiper.php do CommerceKit):
 *   post meta `commercekit_image_gallery` no produto PAI
 *   array [ sanitize_title(<valor da variação>) => "id1,id2,id3" ]
 * mais as chaves reservadas `default_gallery` e `global_gallery`.
 *
 * Escrever aqui é o que faz a galeria trocar sozinha ao escolher a variação, com
 * swiper, thumbs e lightbox nativos — sem JS nosso e sem acoplar ao tema. O
 * CommerceKit só sobrescreve esse meta no save do admin
 * (woocommerce_process_product_meta), então o que gravamos sobrevive.
 *
 * Como cada valor do eixo é 1-a-1 com uma variação no nosso modelo, a chave de
 * um único segmento basta; variações com mais de um atributo são ignoradas
 * (a chave passaria a depender da ORDEM dos atributos e não vale o risco).
 *
 * @param int   $parent_id
 * @param array $map        [ slug => "csv de attachment ids" ] das variações deste push
 * @param array $axis_slugs todos os slugs do eixo de variação (para limpar os que sumiram)
 */
function ojf_sync_commercekit_gallery($parent_id, $map, $axis_slugs) {
    $parent_id = (int) $parent_id;
    if ($parent_id <= 0) return;
    // Sem o CommerceKit instalado não há meta para manter.
    if (!function_exists('commercekit_get_attribute_gallery')) return;

    $current = get_post_meta($parent_id, 'commercekit_image_gallery', true);
    if (!is_array($current)) $current = [];

    // Sem `default_gallery` o JS do CommerceKit não tem para onde voltar quando a
    // seleção é limpa (reset_data). O CommerceKit só cria essa chave no save do
    // admin, e produtos nossos nascem pela REST — então semeia com a galeria do
    // próprio pai.
    if (!isset($current['default_gallery'])) {
        $parent = wc_get_product($parent_id);
        if ($parent) {
            $pids = [];
            if ($parent->get_image_id()) $pids[] = (int) $parent->get_image_id();
            foreach ((array) $parent->get_gallery_image_ids() as $g) $pids[] = (int) $g;
            $pids = array_values(array_unique(array_filter($pids)));
            if ($pids) $current['default_gallery'] = implode(',', $pids);
        }
    }

    $next = [];
    foreach ($current as $slug => $csv) {
        // Preserva o que não é nosso: as chaves reservadas do CommerceKit e
        // qualquer galeria de atributo curada à mão fora do eixo de variação.
        if ($slug === 'default_gallery' || $slug === 'global_gallery') { $next[$slug] = $csv; continue; }
        if (!in_array((string) $slug, $axis_slugs, true)) { $next[$slug] = $csv; continue; }
        // Chave do nosso eixo: só sobrevive se este push ainda a produziu.
    }
    foreach ($map as $slug => $csv) {
        if ($csv !== '') $next[$slug] = $csv;
    }

    if ($next !== $current) update_post_meta($parent_id, 'commercekit_image_gallery', $next);
}

/* ── FRONT ────────────────────────────────────────────────────────────────── */

/**
 * Injeta o HTML da galeria da variação no JSON que o WooCommerce entrega ao
 * `wc-add-to-cart-variation.js` (tanto embutido em data-product_variations
 * quanto na resposta AJAX de lojas acima do variation threshold).
 *
 * O HTML é gerado por wc_get_gallery_image_html() — a MESMA função do core —
 * para o markup sair idêntico ao do tema (data-thumb, data-large_image, etc).
 */
add_filter('woocommerce_available_variation', 'ojf_variation_gallery_available_variation', 10, 3);
function ojf_variation_gallery_available_variation($data, $product, $variation) {
    if (!function_exists('wc_get_gallery_image_html')) return $data;
    if (!$variation instanceof WC_Product_Variation) return $data;

    $ids = ojf_variation_gallery_image_ids($variation->get_id());
    // 0 ou 1 imagem: o swap nativo do WooCommerce já resolve, não mexe.
    if (count($ids) < 2) return $data;

    $html = '';
    foreach ($ids as $i => $id) {
        $html .= wc_get_gallery_image_html($id, $i === 0);
    }
    if ($html === '') return $data;

    $data['ojf_gallery_html'] = $html;
    return $data;
}

/**
 * JS da troca. Inline no handle do core (o plugin inteiro é self-contained —
 * não há diretório assets/ nem CSS/JS em arquivo).
 */
add_action('wp_enqueue_scripts', 'ojf_variation_gallery_front_assets', 30);
function ojf_variation_gallery_front_assets() {
    if (!function_exists('is_product') || !is_product()) return;
    if (!wp_script_is('wc-add-to-cart-variation', 'enqueued') && !wp_script_is('wc-add-to-cart-variation', 'registered')) return;

    $js = <<<'JS'
(function ($) {
  'use strict';
  if (!$ || typeof $.fn.wc_product_gallery !== 'function') return; // sem re-init seguro: não mexe

  $(function () {
    var $form = $('.variations_form').first();
    var $gallery = $('.woocommerce-product-gallery').first();
    if (!$form.length || !$gallery.length) return;

    var original = null;

    function wrapper() {
      return $gallery.find('.woocommerce-product-gallery__wrapper').first();
    }

    // Desmonta o que o ProductGallery do WooCommerce montou. Sem isto o re-init
    // duplica a lupa do photoswipe, os handlers de clique e o flexslider.
    function teardown($w) {
      if ($gallery.find('.flex-viewport').length) $w.unwrap();
      $gallery.find('.flex-control-nav, .flex-direction-nav').remove();
      $gallery.find('.woocommerce-product-gallery__trigger').remove();
      $gallery.find('.zoomImg').remove();
      $gallery.off('click', '.woocommerce-product-gallery__trigger');
      $gallery.off('click', '.woocommerce-product-gallery__image a');
      $gallery.off('woocommerce_gallery_reset_slide_position woocommerce_gallery_init_zoom woocommerce_gallery_init_slider');
      $w.removeClass('flexslider').removeAttr('style');
    }

    function render(html) {
      var $w = wrapper();
      if (!$w.length || !html) return;
      if (original === null) original = $w.html();
      teardown($w);
      $w.html(html);
      $gallery.wc_product_gallery();
      $gallery.trigger('woocommerce_gallery_reset_slide_position');
    }

    // Roda DEPOIS do handler do core (que só troca a imagem principal), porque
    // este callback é registrado depois no ready.
    $form.on('found_variation.ojfGallery', function (event, variation) {
      if (variation && variation.ojf_gallery_html) render(variation.ojf_gallery_html);
    });

    $form.on('reset_data.ojfGallery', function () {
      if (original !== null) render(original);
    });
  });
})(window.jQuery);
JS;

    wp_add_inline_script('wc-add-to-cart-variation', $js);
}

/* ── ADMIN ────────────────────────────────────────────────────────────────── */

/**
 * Campo "Galeria da variação" na aba Variações do produto variável.
 * O worker sobrescreve este meta a cada push (a origem é a fonte da verdade);
 * a curadoria manual vale até o próximo sync do SKU.
 */
add_action('woocommerce_product_after_variable_attributes', 'ojf_variation_gallery_admin_field', 20, 3);
function ojf_variation_gallery_admin_field($loop, $variation_data, $variation) {
    $ids = function_exists('ojf_get_variation_gallery_ids') ? ojf_get_variation_gallery_ids($variation->ID) : [];
    ?>
    <div class="form-row form-row-full ojf-vg">
        <label><?php esc_html_e('Galeria da variação (fotos extras)', 'odontojf'); ?></label>
        <ul class="ojf-vg-list">
            <?php foreach ($ids as $id) : ?>
                <li data-attachment-id="<?php echo esc_attr($id); ?>">
                    <?php echo wp_get_attachment_image((int) $id, 'thumbnail'); ?>
                    <a href="#" class="ojf-vg-remove" aria-label="<?php esc_attr_e('Remover', 'odontojf'); ?>">&times;</a>
                </li>
            <?php endforeach; ?>
        </ul>
        <input type="hidden" class="ojf-vg-input"
               name="ojf_variation_gallery[<?php echo esc_attr($loop); ?>]"
               value="<?php echo esc_attr(implode(',', $ids)); ?>" />
        <p class="description" style="margin:6px 0 0">
            <a href="#" class="button ojf-vg-add"><?php esc_html_e('Adicionar imagens', 'odontojf'); ?></a>
            <span style="margin-left:8px">
                <?php esc_html_e('A imagem principal da variação continua sendo o campo acima; estas aparecem depois dela.', 'odontojf'); ?>
            </span>
        </p>
    </div>
    <?php
}

/**
 * Salva a galeria. O WooCommerce já validou o nonce antes de disparar este hook
 * (WC_AJAX::save_variations / WC_Meta_Box_Product_Data::save_variations).
 */
add_action('woocommerce_save_product_variation', 'ojf_variation_gallery_admin_save', 20, 2);
function ojf_variation_gallery_admin_save($variation_id, $i) {
    if (!isset($_POST['ojf_variation_gallery'][$i])) return;

    $raw = wp_unslash($_POST['ojf_variation_gallery'][$i]); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
    $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', (string) $raw)))));

    if ($ids) update_post_meta((int) $variation_id, '_odontojf_variation_gallery', implode(',', $ids));
    else      delete_post_meta((int) $variation_id, '_odontojf_variation_gallery');
}

add_action('admin_enqueue_scripts', 'ojf_variation_gallery_admin_assets');
function ojf_variation_gallery_admin_assets($hook) {
    if (!in_array($hook, ['post.php', 'post-new.php'], true)) return;
    if (get_post_type() !== 'product') return;
    if (!wp_script_is('wc-admin-variation-meta-boxes', 'registered')) return;

    wp_enqueue_media();

    wp_add_inline_style('woocommerce_admin_styles',
        '.ojf-vg-list{margin:4px 0 0;padding:0;list-style:none;display:flex;flex-wrap:wrap;gap:6px}'
      . '.ojf-vg-list li{position:relative;line-height:0}'
      . '.ojf-vg-list img{width:56px;height:56px;object-fit:cover;border:1px solid #ddd;border-radius:3px}'
      . '.ojf-vg-remove{position:absolute;top:-6px;right:-6px;width:18px;height:18px;line-height:16px;'
      . 'text-align:center;background:#b32d2e;color:#fff;border-radius:50%;text-decoration:none;font-size:13px}'
    );

    $js = <<<'JS'
jQuery(function ($) {
  var $panel = $('#variable_product_options');
  if (!$panel.length || !window.wp || !wp.media) return;

  function sync($box) {
    var ids = $box.find('.ojf-vg-list li').map(function () {
      return $(this).data('attachment-id');
    }).get();
    $box.find('.ojf-vg-input').val(ids.join(','));
    // marca a variação como suja para o "Salvar alterações" do WooCommerce
    $box.closest('.woocommerce_variation').addClass('variation-needs-update');
    $('button.cancel-variation-changes, button.save-variation-changes').prop('disabled', false);
  }

  $panel.on('click', '.ojf-vg-add', function (e) {
    e.preventDefault();
    var $box = $(this).closest('.ojf-vg');
    var frame = wp.media({
      title: 'Imagens da variação',
      button: { text: 'Usar estas imagens' },
      library: { type: 'image' },
      multiple: true
    });
    frame.on('select', function () {
      var $list = $box.find('.ojf-vg-list');
      frame.state().get('selection').each(function (attachment) {
        var a = attachment.toJSON();
        if ($list.find('[data-attachment-id="' + a.id + '"]').length) return;
        var url = (a.sizes && a.sizes.thumbnail) ? a.sizes.thumbnail.url : a.url;
        $list.append(
          '<li data-attachment-id="' + a.id + '"><img src="' + url + '" alt="" />' +
          '<a href="#" class="ojf-vg-remove">&times;</a></li>'
        );
      });
      sync($box);
    });
    frame.open();
  });

  $panel.on('click', '.ojf-vg-remove', function (e) {
    e.preventDefault();
    var $box = $(this).closest('.ojf-vg');
    $(this).closest('li').remove();
    sync($box);
  });
});
JS;

    wp_add_inline_script('wc-admin-variation-meta-boxes', $js);
}
