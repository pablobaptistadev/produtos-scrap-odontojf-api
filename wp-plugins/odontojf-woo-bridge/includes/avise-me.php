<?php
/**
 * Avise-me (>= 1.0.80): produto ou variação sem estoque → o cliente deixa nome,
 * e-mail e WhatsApp, e recebe um e-mail quando o item volta.
 *
 *  - Na página do produto (>= 1.0.81), o formulário fica na própria página,
 *    logo depois do "fora de estoque e indisponível" do Woo; no grid, botão
 *    "Avise-me" (sino) no lugar de "Adicionar", que abre o popup.
 *  - "Indisponível" = não dá para comprar: sem estoque OU sem preço (o Woo
 *    esconde variação sem preço e mostra o produto como indisponível).
 *    Produto sob orçamento não tem Avise-me: "Solicitar orçamento" ganha.
 *  - Popup próprio (um só por página, impresso no rodapé).
 *  - Lista de espera na tabela {prefix}ojf_avise_me.
 *  - Quando o Woo marca o item como "em estoque" (push da origem, carga de
 *    estoque ou edição manual), um lote envia o e-mail para quem espera aquele
 *    item e marca como avisado. Um aviso por pedido.
 *  - Tela em WooCommerce → Avise-me: lista, mais esperados e e-mail de teste.
 */

if (!defined('ABSPATH')) exit;

define('OJF_AVISE_DB', '1');
define('OJF_AVISE_TEAL', '#1b9797');
define('OJF_AVISE_DARK', '#004635');

function ojf_avise_table() {
    global $wpdb;
    return $wpdb->prefix . 'ojf_avise_me';
}

/* ── tabela ─────────────────────────────────────────────────────────────── */

add_action('init', function () {
    if (get_option('ojf_avise_db') === OJF_AVISE_DB) return;
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $t = ojf_avise_table();
    // sem "IF NOT EXISTS": o dbDelta lê a 3ª palavra como nome da tabela
    dbDelta("CREATE TABLE {$t} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        product_id BIGINT UNSIGNED NOT NULL,
        variation_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        name VARCHAR(120) NOT NULL DEFAULT '',
        email VARCHAR(190) NOT NULL,
        whatsapp VARCHAR(20) NOT NULL DEFAULT '',
        status VARCHAR(20) NOT NULL DEFAULT 'waiting',
        created_at DATETIME NOT NULL,
        notified_at DATETIME NULL,
        ip VARCHAR(45) NOT NULL DEFAULT '',
        PRIMARY KEY  (id),
        KEY item (product_id, variation_id, status),
        KEY email (email),
        KEY status (status)
    ) " . $wpdb->get_charset_collate() . ";");
    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $t)) === $t) update_option('ojf_avise_db', OJF_AVISE_DB, true);
}, 5);

/* ── regras ─────────────────────────────────────────────────────────────── */

/** Dá para comprar este produto simples / esta variação agora? */
function ojf_avise_buyable($o) {
    if (!$o instanceof WC_Product || !$o->is_in_stock() || !$o->is_purchasable()) return false;
    return !$o->is_type('variation') || $o->variation_is_visible();
}

/**
 * O cliente não consegue comprar: simples sem estoque ou sem preço; variável
 * sem nenhuma variação comprável (é quando o Woo escreve "Este produto está
 * fora de estoque e indisponível").
 */
function ojf_avise_unavailable($product) {
    if (!$product instanceof WC_Product) return false;
    if ($product->is_type('variable')) {
        if (!$product->is_in_stock()) return true;
        foreach ($product->get_children() as $vid) {
            if (ojf_avise_buyable(wc_get_product($vid))) return false;
        }
        return true;
    }
    return !ojf_avise_buyable($product);
}

/** O item esperado (produto ou variação) já pode ser comprado? */
function ojf_avise_item_in_stock($product_id, $variation_id = 0) {
    if ($variation_id) return ojf_avise_buyable(wc_get_product((int) $variation_id));
    $p = wc_get_product((int) $product_id);
    return $p ? !ojf_avise_unavailable($p) : false;
}

function ojf_avise_blocked_by_budget($product) {
    return function_exists('ojf_product_needs_budget') && ojf_product_needs_budget($product);
}

function ojf_avise_bell_svg() {
    return '<svg class="ojf-avise-ico" viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>';
}

/**
 * O botão. $hidden: no produto variável ele nasce escondido e o script mostra
 * quando a variação escolhida está esgotada.
 */
function ojf_avise_button_html($product, $variation_id = 0, $hidden = false, $context = 'widget') {
    $GLOBALS['ojf_avise_needed'] = true;
    return sprintf(
        '<button type="button" class="ojf-avise-btn ojf-avise-btn--%s" data-ojf-avise data-product-id="%d" data-variation-id="%d" data-product-name="%s"%s>%s<span>Avise-me</span></button>',
        esc_attr($context),
        (int) ($product->is_type('variation') ? $product->get_parent_id() : $product->get_id()),
        (int) $variation_id,
        esc_attr(wp_strip_all_tags($product->get_name())),
        $hidden ? ' hidden' : '',
        ojf_avise_bell_svg()
    );
}

/**
 * Formulário na própria página do produto. $variacao: nasce escondido e o
 * script mostra quando a variação escolhida não pode ser comprada.
 */
function ojf_avise_box_html($product, $variacao = false) {
    static $n = 0;
    $n++;
    $GLOBALS['ojf_avise_needed'] = true;
    // na própria página o nome já está à vista (e o do ERP às vezes vem com
    // acento quebrado): "este produto", ou o nome da variação escolhida
    $id   = 'ojf-avise-b' . $n;
    ob_start(); ?>
<div class="ojf-avise-box<?php echo $variacao ? ' ojf-avise-box--variacao' : ''; ?>" data-ojf-avise-box data-product-id="<?php echo (int) $product->get_id(); ?>" data-product-name=""<?php echo $variacao ? ' hidden' : ''; ?>>
  <div class="ojf-avise-box__head">
    <span class="ojf-avise-box__bell"><?php echo ojf_avise_bell_svg(); // phpcs:ignore ?></span>
    <div><strong class="ojf-avise-box__title">Avise-me quando chegar</strong>
    <span class="ojf-avise-box__text">Deixe seu contato e avisamos você assim que <b data-ojf-avise-nome>este produto</b> estiver disponível.</span></div>
  </div>
  <form class="ojf-avise-box__form" data-ojf-avise-form novalidate>
    <input type="hidden" name="product_id" value="<?php echo (int) $product->get_id(); ?>"><input type="hidden" name="variation_id" value="0">
    <div class="ojf-avise__hp" aria-hidden="true"><label>Empresa <input type="text" name="empresa" tabindex="-1" autocomplete="off"></label></div>
    <div class="ojf-avise__field" data-field="name"><label for="<?php echo $id; ?>-n">Seu nome</label><input id="<?php echo $id; ?>-n" name="name" type="text" autocomplete="name" placeholder="Como podemos te chamar?" required></div>
    <div class="ojf-avise__field" data-field="email"><label for="<?php echo $id; ?>-e">E-mail</label><input id="<?php echo $id; ?>-e" name="email" type="email" autocomplete="email" inputmode="email" placeholder="voce@exemplo.com" required></div>
    <div class="ojf-avise__field" data-field="whatsapp"><label for="<?php echo $id; ?>-w">WhatsApp</label><input id="<?php echo $id; ?>-w" name="whatsapp" type="tel" autocomplete="tel" inputmode="numeric" placeholder="(00) 00000-0000" maxlength="15" required></div>
    <div class="ojf-avise__msg" role="alert"></div>
    <button type="submit" class="ojf-avise__submit"><?php echo ojf_avise_bell_svg(); // phpcs:ignore ?><span>Avise-me</span></button>
  </form>
  <div class="ojf-avise-box__ok" role="status"><span class="ojf-avise__ok-ico">&#10003;</span><div><strong>Pronto!</strong> <span data-ojf-avise-ok></span></div></div>
</div>
<?php
    return (string) ob_get_clean();
}

/*
 * Página do produto: logo depois do template de compra do Woo (o Elementor Pro
 * e o nosso widget passam por ele). Produto indisponível → formulário à vista;
 * variável com opções compráveis → formulário escondido, aparece se a opção
 * escolhida não puder ser comprada.
 */
function ojf_avise_after_add_to_cart() {
    global $product;
    if (!$product instanceof WC_Product || ojf_avise_blocked_by_budget($product)) return;
    // em cada widget de compra (a página pode ter um escondido por dispositivo)
    if (ojf_avise_unavailable($product)) echo ojf_avise_box_html($product); // phpcs:ignore
    elseif ($product->is_type('variable')) echo ojf_avise_box_html($product, true); // phpcs:ignore
}
add_action('woocommerce_simple_add_to_cart', 'ojf_avise_after_add_to_cart', 31);
add_action('woocommerce_variable_add_to_cart', 'ojf_avise_after_add_to_cart', 31);

/** Widget de compra (class-ojf-add-to-cart-widget.php): o formulário já sai pelo template do Woo. */
function ojf_avise_widget_html($product) {
    return '';
}

/* grid (listagem JetEngine com o widget "Adicionar ao carrinho" do Elementor) */
add_filter('elementor/widget/render_content', function ($content, $widget) {
    if (!is_object($widget) || !method_exists($widget, 'get_name') || $widget->get_name() !== 'wc-add-to-cart') return $content;
    $product = wc_get_product(get_the_ID());
    if (!$product || !ojf_avise_unavailable($product) || ojf_avise_blocked_by_budget($product)) return $content;
    return ojf_avise_button_html($product, 0, false, 'grid');
}, 25, 2);

/* listas padrão do Woo (busca, relacionados) */
add_filter('woocommerce_loop_add_to_cart_link', function ($html, $product) {
    if (!$product instanceof WC_Product || !ojf_avise_unavailable($product) || ojf_avise_blocked_by_budget($product)) return $html;
    return ojf_avise_button_html($product, 0, false, 'grid');
}, 25, 2);

/* ── cadastro ───────────────────────────────────────────────────────────── */

function ojf_avise_client_ip() {
    foreach (['HTTP_CF_CONNECTING_IP', 'REMOTE_ADDR'] as $k) {
        $ip = trim((string) ($_SERVER[$k] ?? ''));
        if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
    }
    return '';
}

function ojf_avise_register($p) {
    global $wpdb;
    if (!empty($p['empresa'])) return ['ok' => true, 'message' => 'Pronto!']; // armadilha para robô

    $pid   = (int) ($p['product_id'] ?? 0);
    $vid   = (int) ($p['variation_id'] ?? 0);
    $name  = trim(sanitize_text_field((string) ($p['name'] ?? '')));
    $email = sanitize_email((string) ($p['email'] ?? ''));
    $wa    = preg_replace('/\D+/', '', (string) ($p['whatsapp'] ?? ''));

    $produto = $pid ? wc_get_product($pid) : null;
    if (!$produto || $produto->is_type('variation')) return ['ok' => false, 'message' => 'Produto não encontrado.'];
    if ($vid) {
        $var = wc_get_product($vid);
        if (!$var || !$var->is_type('variation') || (int) $var->get_parent_id() !== $pid) return ['ok' => false, 'message' => 'Opção do produto não encontrada.'];
    }
    if (mb_strlen($name) < 2) return ['ok' => false, 'message' => 'Informe seu nome.', 'field' => 'name'];
    if (!is_email($email)) return ['ok' => false, 'message' => 'Informe um e-mail válido.', 'field' => 'email'];
    if (strlen($wa) === 13 && strpos($wa, '55') === 0) $wa = substr($wa, 2);
    if (strlen($wa) < 10 || strlen($wa) > 11) return ['ok' => false, 'message' => 'Informe o WhatsApp com DDD.', 'field' => 'whatsapp'];

    $ip  = ojf_avise_client_ip();
    $key = 'ojf_avise_rl_' . md5($ip);
    $n   = (int) get_transient($key);
    if ($n >= 8) return ['ok' => false, 'message' => 'Muitos pedidos seguidos. Tente de novo em alguns minutos.'];
    set_transient($key, $n + 1, 10 * MINUTE_IN_SECONDS);

    $t = ojf_avise_table();
    $existe = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM {$t} WHERE email = %s AND product_id = %d AND variation_id = %d AND status = 'waiting' LIMIT 1",
        $email, $pid, $vid
    ));
    $dados = ['name' => mb_substr($name, 0, 120), 'whatsapp' => $wa];
    if ($existe) {
        $wpdb->update($t, $dados, ['id' => $existe]);
    } else {
        $wpdb->insert($t, $dados + [
            'product_id' => $pid, 'variation_id' => $vid, 'email' => mb_substr($email, 0, 190),
            'status' => 'waiting', 'created_at' => current_time('mysql'), 'ip' => $ip,
        ]);
        if (!$wpdb->insert_id) return ['ok' => false, 'message' => 'Não conseguimos salvar agora. Tente de novo.'];
    }
    return ['ok' => true, 'message' => 'Pronto! Avisaremos você assim que chegar.'];
}

add_action('rest_api_init', function () {
    register_rest_route('odontojf/v1', '/avise-me', [
        'methods' => 'POST', 'permission_callback' => '__return_true',
        'callback' => function (WP_REST_Request $r) {
            $p = $r->get_json_params();
            if (!is_array($p) || !$p) $p = $r->get_params();
            $res = ojf_avise_register((array) $p);
            return new WP_REST_Response($res, $res['ok'] ? 200 : 422);
        },
    ]);
});

/* ── aviso quando o estoque volta ───────────────────────────────────────── */

function ojf_avise_mark_dirty() {
    update_option('ojf_avise_dirty', time(), true);
    if (function_exists('ojf_job_kick')) ojf_job_kick('avise');
}
add_action('woocommerce_product_set_stock_status', function ($id, $status) {
    if ($status === 'instock') ojf_avise_mark_dirty();
}, 10, 2);
add_action('woocommerce_variation_set_stock_status', function ($id, $status) {
    if ($status === 'instock') ojf_avise_mark_dirty();
}, 10, 2);
// ganhou preço (ou qualquer outra mudança) num item que alguém espera: confere
function ojf_avise_maybe_dirty($id) {
    global $wpdb;
    $pid = (int) (wp_get_post_parent_id((int) $id) ?: $id);
    $t = ojf_avise_table();
    if (get_option('ojf_avise_db') !== OJF_AVISE_DB) return;
    if ($wpdb->get_var($wpdb->prepare("SELECT 1 FROM {$t} WHERE product_id = %d AND status = 'waiting' LIMIT 1", $pid))) ojf_avise_mark_dirty();
}
add_action('woocommerce_update_product', 'ojf_avise_maybe_dirty', 20);
add_action('woocommerce_update_product_variation', 'ojf_avise_maybe_dirty', 20);

function ojf_avise_pending() {
    return (bool) get_option('ojf_avise_dirty');
}

/** Varre a fila de espera e avisa quem espera item que já está disponível. */
function ojf_avise_step() {
    global $wpdb;
    $t  = ojf_avise_table();
    $st = get_option('ojf_avise_scan', []);
    if (!is_array($st) || empty($st['started'])) $st = ['started' => time(), 'cursor' => 0, 'sent' => 0, 'failed' => 0];

    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$t} WHERE status = 'waiting' AND id > %d ORDER BY id ASC LIMIT 40", (int) $st['cursor']
    ));
    foreach ((array) $rows as $row) {
        $st['cursor'] = (int) $row->id;
        if (!ojf_avise_item_in_stock($row->product_id, $row->variation_id)) continue;
        if (ojf_avise_send($row)) {
            $wpdb->update($t, ['status' => 'notified', 'notified_at' => current_time('mysql')], ['id' => $row->id]);
            $st['sent']++;
        } else {
            $st['failed']++;
        }
    }
    if (count((array) $rows) < 40) {
        // passada completa: só desliga se nada voltou ao estoque depois que ela começou
        if ((int) get_option('ojf_avise_dirty') <= (int) $st['started']) delete_option('ojf_avise_dirty');
        update_option('ojf_avise_last_scan', $st + ['finished' => time()], false);
        delete_option('ojf_avise_scan');
        return;
    }
    update_option('ojf_avise_scan', $st, false);
}

if (function_exists('ojf_job_register')) ojf_job_register('avise', 'ojf_avise_pending', 'ojf_avise_step', 2);

/* ── e-mail ─────────────────────────────────────────────────────────────── */

function ojf_avise_logo_url() {
    $id = (int) get_theme_mod('custom_logo');
    $u  = $id ? wp_get_attachment_image_url($id, 'medium') : '';
    if (!$u) $u = (string) get_option('woocommerce_email_header_image');
    return $u ?: content_url('uploads/2024/12/logo001.png');
}

function ojf_avise_send($row) {
    $produto = wc_get_product((int) $row->product_id);
    if (!$produto) return false;
    $var = $row->variation_id ? wc_get_product((int) $row->variation_id) : null;
    $titulo = wp_strip_all_tags(($var ?: $produto)->get_name());
    $html = ojf_avise_email_html($row, $produto, $var);
    $from_name = get_option('woocommerce_email_from_name') ?: get_bloginfo('name');
    $from_mail = get_option('woocommerce_email_from_address') ?: get_option('admin_email');
    $headers = ['Content-Type: text/html; charset=UTF-8', sprintf('From: %s <%s>', $from_name, $from_mail)];
    return wp_mail($row->email, sprintf('Chegou! %s está disponível', $titulo), $html, $headers);
}

/** E-mail "chegou": tabelas e estilos inline (é o que os clientes de e-mail entendem). */
function ojf_avise_email_html($row, $produto, $var = null) {
    $item   = $var ?: $produto;
    $nome   = trim((string) $row->name);
    $primeiro = $nome !== '' ? explode(' ', $nome)[0] : '';
    $titulo = wp_strip_all_tags($item->get_name());
    $url    = add_query_arg(['utm_source' => 'avise-me', 'utm_medium' => 'email', 'utm_campaign' => 'estoque'], $item->get_permalink());
    $img_id = $item->get_image_id() ?: $produto->get_image_id();
    $img    = $img_id ? wp_get_attachment_image_url($img_id, 'woocommerce_single') : wc_placeholder_img_src();
    $preco  = (float) $item->get_price() > 0 ? wp_strip_all_tags(wc_price($item->get_price())) : '';
    $marca  = trim((string) $produto->get_meta('_odontojf_brand', true));
    $loja   = get_bloginfo('name');
    $site   = home_url('/');
    $logo   = ojf_avise_logo_url();
    $teal   = OJF_AVISE_TEAL;
    $dark   = OJF_AVISE_DARK;
    $e = 'esc_html';

    ob_start(); ?>
<!doctype html>
<html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?php echo $e($titulo); ?></title></head>
<body style="margin:0;padding:0;background:#eef3f3;font-family:Montserrat,Segoe UI,Helvetica,Arial,sans-serif;color:#1f2d3a">
<div style="display:none;max-height:0;overflow:hidden;opacity:0"><?php echo $e($titulo); ?> está disponível de novo. Garanta o seu antes que acabe.</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#eef3f3;padding:28px 12px">
<tr><td align="center">
  <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="width:100%;max-width:600px;background:#ffffff;border-radius:18px;overflow:hidden;box-shadow:0 6px 24px rgba(0,70,53,.08)">
    <tr><td align="center" style="padding:26px 24px 18px">
      <a href="<?php echo esc_url($site); ?>"><img src="<?php echo esc_url($logo); ?>" alt="<?php echo esc_attr($loja); ?>" width="170" style="display:block;width:170px;max-width:60%;height:auto;border:0"></a>
    </td></tr>
    <tr><td style="background:<?php echo $dark; ?>;background:linear-gradient(135deg,<?php echo $dark; ?> 0%,<?php echo $teal; ?> 100%);padding:34px 32px 30px;text-align:center">
      <div style="display:inline-block;background:rgba(255,255,255,.14);color:#ffffff;border-radius:999px;padding:6px 14px;font-size:12px;font-weight:700;letter-spacing:.12em;text-transform:uppercase">&#128276;&nbsp; Chegou</div>
      <h1 style="margin:16px 0 8px;color:#ffffff;font-size:26px;line-height:1.25;font-weight:800"><?php echo $primeiro !== '' ? $e($primeiro) . ', o' : 'O'; ?> que você esperava voltou ao estoque!</h1>
      <p style="margin:0;color:#d9f2f2;font-size:15px;line-height:1.6">Você pediu para ser avisado — e ele acabou de chegar.</p>
    </td></tr>
    <tr><td style="padding:30px 32px 6px">
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e3ecec;border-radius:16px">
        <tr>
          <td width="190" valign="middle" style="padding:18px;width:190px" align="center">
            <a href="<?php echo esc_url($url); ?>"><img src="<?php echo esc_url($img); ?>" alt="<?php echo esc_attr($titulo); ?>" width="160" style="display:block;width:160px;max-width:100%;height:auto;border-radius:12px;border:0;background:#f7fafa"></a>
          </td>
          <td valign="middle" style="padding:18px 20px 18px 4px">
            <?php if ($marca !== '') : ?><div style="color:<?php echo $teal; ?>;font-size:12px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;margin-bottom:6px"><?php echo $e($marca); ?></div><?php endif; ?>
            <div style="font-size:17px;line-height:1.4;font-weight:700;color:#1f2d3a;margin-bottom:10px"><?php echo $e($titulo); ?></div>
            <div style="display:inline-block;background:#e6f6ee;color:#0f7a4a;border-radius:999px;padding:5px 12px;font-size:12px;font-weight:700">&#9679;&nbsp; Disponível agora</div>
            <?php if ($preco !== '') : ?><div style="margin-top:12px;font-size:22px;font-weight:800;color:<?php echo $dark; ?>"><?php echo $e($preco); ?></div><?php endif; ?>
          </td>
        </tr>
      </table>
    </td></tr>
    <tr><td align="center" style="padding:26px 32px 8px">
      <a href="<?php echo esc_url($url); ?>" style="display:inline-block;background:<?php echo $teal; ?>;color:#ffffff;text-decoration:none;font-size:16px;font-weight:800;padding:16px 38px;border-radius:12px;box-shadow:0 6px 16px rgba(27,151,151,.35)">Comprar agora &rarr;</a>
    </td></tr>
    <tr><td align="center" style="padding:8px 32px 30px">
      <p style="margin:0;color:#6b7785;font-size:13px;line-height:1.6">O estoque é limitado e costuma acabar rápido.</p>
    </td></tr>
    <tr><td style="background:#f6f9f9;padding:22px 32px;text-align:center;border-top:1px solid #e3ecec">
      <p style="margin:0 0 6px;color:#6b7785;font-size:12px;line-height:1.6">Você recebeu este e-mail porque pediu para ser avisado quando este produto voltasse ao estoque em <a href="<?php echo esc_url($site); ?>" style="color:<?php echo $teal; ?>;text-decoration:none;font-weight:700"><?php echo $e($loja); ?></a>. O aviso é enviado uma única vez.</p>
    </td></tr>
  </table>
</td></tr>
</table>
</body></html>
<?php
    return (string) ob_get_clean();
}

/* ── front: popup, estilos e script (uma vez por página) ────────────────── */

add_action('wp_footer', function () {
    if (empty($GLOBALS['ojf_avise_needed']) && !(function_exists('is_product') && is_product())) return;
    $rest = esc_url_raw(rest_url('odontojf/v1/avise-me'));
    $teal = OJF_AVISE_TEAL;
    $dark = OJF_AVISE_DARK;
    ?>
<style id="ojf-avise-css">
.ojf-avise-btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;width:100%;min-height:44px;padding:10px 18px;border:0;border-radius:8px;background:#5f6b76;color:#fff;font:600 15px/1.2 Montserrat,inherit;cursor:pointer;transition:background .2s,transform .1s}
.ojf-avise-btn:hover{background:#4c5761}.ojf-avise-btn:active{transform:scale(.98)}
.ojf-avise-btn[hidden]{display:none!important}
.ojf-avise-btn--widget,.ojf-avise-btn--variacao{margin-top:12px;min-height:52px;font-size:16px;border-radius:10px}
.ojf-avise{position:fixed;inset:0;z-index:999999;display:none;align-items:center;justify-content:center;padding:16px;background:rgba(0,38,29,.55);backdrop-filter:blur(3px);-webkit-backdrop-filter:blur(3px)}
.ojf-avise.is-open{display:flex;animation:ojfAviseFade .18s ease-out}
@keyframes ojfAviseFade{from{opacity:0}to{opacity:1}}
@keyframes ojfAviseUp{from{transform:translateY(14px);opacity:0}to{transform:none;opacity:1}}
.ojf-avise__card{position:relative;width:100%;max-width:440px;max-height:calc(100vh - 32px);overflow:auto;background:#fff;border-radius:20px;box-shadow:0 24px 60px rgba(0,0,0,.25);font-family:Montserrat,system-ui,sans-serif;color:#1f2d3a;animation:ojfAviseUp .22s ease-out}
.ojf-avise__top{padding:28px 28px 0;text-align:center}
.ojf-avise__bell{width:56px;height:56px;margin:0 auto 14px;border-radius:50%;display:flex;align-items:center;justify-content:center;background:#e4f4f4;color:<?php echo $teal; ?>}
.ojf-avise__bell svg{width:26px;height:26px}
.ojf-avise__title{margin:0;font-size:21px;font-weight:800;color:<?php echo $dark; ?>}
.ojf-avise__text{margin:10px 0 0;font-size:14px;line-height:1.6;color:#55626e}
.ojf-avise__text strong{color:#1f2d3a}
.ojf-avise__close{position:absolute;top:12px;right:12px;width:36px;height:36px;border:0;border-radius:50%;background:transparent;color:#7a8794;font-size:22px;line-height:1;cursor:pointer}
.ojf-avise__close:hover{background:#f1f4f5;color:#1f2d3a}
.ojf-avise__form{padding:20px 28px 26px}
.ojf-avise__field{margin-bottom:14px}
.ojf-avise__field label{display:block;margin-bottom:6px;font-size:13px;font-weight:700;color:#3a4652}
.ojf-avise__field input{width:100%;box-sizing:border-box;height:48px;padding:0 14px;border:1.5px solid #d8e0e4;border-radius:12px;background:#fbfcfc;font:500 15px Montserrat,system-ui,sans-serif;color:#1f2d3a;transition:border-color .15s,box-shadow .15s}
.ojf-avise__field input:focus{outline:0;border-color:<?php echo $teal; ?>;box-shadow:0 0 0 4px rgba(27,151,151,.15);background:#fff}
.ojf-avise__field.is-error input{border-color:#d64545;box-shadow:0 0 0 4px rgba(214,69,69,.12)}
.ojf-avise__hp{position:absolute!important;left:-9999px!important;width:1px;height:1px;overflow:hidden}
.ojf-avise__msg{min-height:20px;margin:2px 0 12px;font-size:13px;font-weight:600;color:#d64545}
.ojf-avise__actions{display:flex;gap:10px}
.ojf-avise__actions button{flex:1;height:50px;border-radius:12px;font:700 15px Montserrat,system-ui,sans-serif;cursor:pointer;transition:background .15s,border-color .15s,opacity .15s}
.ojf-avise__cancel{border:1.5px solid #d8e0e4;background:#fff;color:#3a4652}
.ojf-avise__cancel:hover{border-color:#b9c4ca}
.ojf-avise__submit{display:inline-flex;align-items:center;justify-content:center;gap:8px;border:0;background:<?php echo $teal; ?>;color:#fff;box-shadow:0 6px 16px rgba(27,151,151,.3)}
.ojf-avise__submit:hover{background:#168383}
.ojf-avise__submit[disabled]{opacity:.65;cursor:wait}
.ojf-avise__ok{display:none;padding:8px 28px 30px;text-align:center}
.ojf-avise.is-done .ojf-avise__form,.ojf-avise.is-done .ojf-avise__top{display:none}.ojf-avise.is-done .ojf-avise__ok{display:block;padding-top:34px}
.ojf-avise__ok-ico{width:56px;height:56px;margin:4px auto 12px;border-radius:50%;background:#e6f6ee;color:#0f7a4a;display:flex;align-items:center;justify-content:center;font-size:28px;font-weight:800}
.ojf-avise__ok p{margin:0 0 18px;font-size:14px;line-height:1.6;color:#55626e}
.ojf-avise__ok button{width:100%;height:50px;border:0;border-radius:12px;background:<?php echo $teal; ?>;color:#fff;font:700 15px Montserrat,system-ui,sans-serif;cursor:pointer}
.ojf-avise-box{margin:14px 0 4px;padding:18px;border:1.5px solid #cfe6e6;border-radius:14px;background:#f5fbfb;font-family:Montserrat,system-ui,sans-serif;color:#1f2d3a;text-align:left}
.ojf-avise-box[hidden]{display:none!important}
.ojf-avise-box__head{display:flex;gap:12px;align-items:flex-start;margin-bottom:14px}
.ojf-avise-box__bell{flex:0 0 40px;width:40px;height:40px;border-radius:50%;display:flex;align-items:center;justify-content:center;background:#e0f2f2;color:<?php echo $teal; ?>}
.ojf-avise-box__bell svg{width:20px;height:20px}
.ojf-avise-box__title{display:block;font-size:16px;font-weight:800;color:<?php echo $dark; ?>;margin:1px 0 3px}
.ojf-avise-box__text{display:block;font-size:13px;line-height:1.5;color:#55626e}
.ojf-avise-box__text b{color:#1f2d3a;font-weight:700}
.ojf-avise-box .ojf-avise__field{margin-bottom:10px}
.ojf-avise-box .ojf-avise__field input{height:44px;background:#fff}
.ojf-avise-box .ojf-avise__msg{margin:0 0 8px;min-height:0}
.ojf-avise-box .ojf-avise__submit{width:100%;height:48px;border-radius:12px;font:700 15px Montserrat,system-ui,sans-serif;cursor:pointer}
.ojf-avise-box__ok{display:none;gap:12px;align-items:center;font-size:14px;line-height:1.5;color:#3a4652}
.ojf-avise-box__ok .ojf-avise__ok-ico{flex:0 0 40px;width:40px;height:40px;margin:0;font-size:20px}
.ojf-avise-box.is-done .ojf-avise-box__form{display:none}.ojf-avise-box.is-done .ojf-avise-box__ok{display:flex}
@media (max-width:480px){.ojf-avise{align-items:flex-end;padding:0}.ojf-avise__card{max-width:none;border-radius:20px 20px 0 0}}
</style>
<div class="ojf-avise" id="ojf-avise" role="dialog" aria-modal="true" aria-labelledby="ojf-avise-title" hidden>
  <div class="ojf-avise__card">
    <button type="button" class="ojf-avise__close" data-ojf-avise-close aria-label="Fechar">&times;</button>
    <div class="ojf-avise__top">
      <div class="ojf-avise__bell"><?php echo ojf_avise_bell_svg(); // phpcs:ignore ?></div>
      <h3 class="ojf-avise__title" id="ojf-avise-title">Avise-me quando chegar</h3>
      <p class="ojf-avise__text">Deixe seu contato e avisaremos quando "<strong data-ojf-avise-nome></strong>" estiver disponível.</p>
    </div>
    <form class="ojf-avise__form" novalidate>
      <input type="hidden" name="product_id"><input type="hidden" name="variation_id">
      <div class="ojf-avise__hp" aria-hidden="true"><label>Empresa <input type="text" name="empresa" tabindex="-1" autocomplete="off"></label></div>
      <div class="ojf-avise__field" data-field="name"><label for="ojf-avise-nome">Seu nome</label><input id="ojf-avise-nome" name="name" type="text" autocomplete="name" placeholder="Como podemos te chamar?" required></div>
      <div class="ojf-avise__field" data-field="email"><label for="ojf-avise-email">E-mail</label><input id="ojf-avise-email" name="email" type="email" autocomplete="email" inputmode="email" placeholder="voce@exemplo.com" required></div>
      <div class="ojf-avise__field" data-field="whatsapp"><label for="ojf-avise-wa">WhatsApp</label><input id="ojf-avise-wa" name="whatsapp" type="tel" autocomplete="tel" inputmode="numeric" placeholder="(00) 00000-0000" maxlength="15" required></div>
      <div class="ojf-avise__msg" role="alert"></div>
      <div class="ojf-avise__actions">
        <button type="button" class="ojf-avise__cancel" data-ojf-avise-close>Cancelar</button>
        <button type="submit" class="ojf-avise__submit"><?php echo ojf_avise_bell_svg(); // phpcs:ignore ?><span>Avise-me</span></button>
      </div>
    </form>
    <div class="ojf-avise__ok">
      <div class="ojf-avise__ok-ico">&#10003;</div>
      <h3 class="ojf-avise__title">Pronto!</h3>
      <p data-ojf-avise-ok></p>
      <button type="button" data-ojf-avise-close>Continuar comprando</button>
    </div>
  </div>
</div>
<script id="ojf-avise-js">
(function(){
  var REST = <?php echo wp_json_encode($rest); ?>;
  var modal = document.getElementById('ojf-avise');
  // o campo "Seu nome" se chama name, que colide com o atributo name do <form>: sempre por seletor
  function campo(form, n){ return form.querySelector('[name="' + n + '"]'); }
  function mascara(v){
    v = String(v).replace(/\D/g,'').slice(0,11);
    if (v.length <= 2) return v.length ? '(' + v : '';
    if (v.length <= 6) return '(' + v.slice(0,2) + ') ' + v.slice(2);
    if (v.length <= 10) return '(' + v.slice(0,2) + ') ' + v.slice(2,6) + '-' + v.slice(6);
    return '(' + v.slice(0,2) + ') ' + v.slice(2,7) + '-' + v.slice(7);
  }
  function salvo(){ try { return JSON.parse(localStorage.getItem('ojfAvise') || '{}'); } catch(e){ return {}; } }
  function preenche(form){
    var s = salvo(), n = campo(form,'name'), e = campo(form,'email'), w = campo(form,'whatsapp');
    if (s.name && !n.value) n.value = s.name; if (s.email && !e.value) e.value = s.email; if (s.whatsapp && !w.value) w.value = mascara(s.whatsapp);
  }
  function limpaErros(form){ var m = form.querySelector('.ojf-avise__msg'); if (m) m.textContent = ''; form.querySelectorAll('.is-error').forEach(function(f){ f.classList.remove('is-error'); }); }
  function erro(form, nome, texto){
    form.querySelector('.ojf-avise__msg').textContent = texto;
    if (nome){ var f = form.querySelector('[data-field="'+nome+'"]'); if (f){ f.classList.add('is-error'); f.querySelector('input').focus(); } }
  }
  function textoOk(el, email, produto){
    el.innerHTML = '';
    el.appendChild(document.createTextNode('Vamos te avisar em '));
    var b1 = document.createElement('strong'); b1.textContent = email; el.appendChild(b1);
    if (produto) {
      el.appendChild(document.createTextNode(' assim que "'));
      var b2 = document.createElement('strong'); b2.textContent = produto; el.appendChild(b2);
      el.appendChild(document.createTextNode('" estiver disponível.'));
    } else {
      el.appendChild(document.createTextNode(' assim que este produto estiver disponível.'));
    }
  }

  // máscara e limpeza de erro em qualquer formulário do Avise-me (popup ou página)
  document.addEventListener('input', function(e){
    var form = e.target.closest('[data-ojf-avise-form], #ojf-avise form'); if (!form) return;
    var f = e.target.closest('.ojf-avise__field'); if (f && f.classList.contains('is-error')){ f.classList.remove('is-error'); form.querySelector('.ojf-avise__msg').textContent = ''; }
    if (e.target.name === 'whatsapp'){ var w = e.target, p = w.selectionStart, a = w.value.length; w.value = mascara(w.value); var d = w.value.length - a; try { w.setSelectionRange(p + d, p + d); } catch(x){} }
  });

  /** valida e envia; ok(dados, produto) quando salvou */
  function enviar(form, produto, ok){
    limpaErros(form);
    var w = campo(form,'whatsapp'), submit = form.querySelector('.ojf-avise__submit');
    var dados = { product_id: campo(form,'product_id').value, variation_id: campo(form,'variation_id').value, name: campo(form,'name').value.trim(), email: campo(form,'email').value.trim(), whatsapp: w.value, empresa: campo(form,'empresa').value };
    if (dados.name.length < 2) return erro(form, 'name', 'Informe seu nome.');
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(dados.email)) return erro(form, 'email', 'Informe um e-mail válido.');
    var dig = w.value.replace(/\D/g,''); if (dig.length < 10) return erro(form, 'whatsapp', 'Informe o WhatsApp com DDD.');
    submit.disabled = true;
    fetch(REST, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(dados), credentials: 'same-origin' })
      .then(function(r){ return r.json().catch(function(){ return { ok: false, message: 'Não conseguimos salvar agora. Tente de novo.' }; }); })
      .then(function(r){
        submit.disabled = false;
        if (!r || !r.ok) return erro(form, r && r.field, (r && r.message) || 'Não conseguimos salvar agora. Tente de novo.');
        try { localStorage.setItem('ojfAvise', JSON.stringify({ name: dados.name, email: dados.email, whatsapp: dig })); } catch(e){}
        ok(dados, produto);
      })
      .catch(function(){ submit.disabled = false; erro(form, null, 'Sem conexão. Tente de novo.'); });
  }

  // formulário na página do produto
  document.querySelectorAll('[data-ojf-avise-box]').forEach(function(box){ preenche(box.querySelector('form')); });
  document.addEventListener('submit', function(e){
    var form = e.target.closest('[data-ojf-avise-form]'); if (!form) return;
    e.preventDefault();
    var box = form.closest('[data-ojf-avise-box]');
    enviar(form, box.getAttribute('data-product-name') || '', function(d, produto){
      textoOk(box.querySelector('[data-ojf-avise-ok]'), d.email, produto);
      box.classList.add('is-done');
    });
  });

  // popup (botão do grid)
  if (modal) {
    var mform = modal.querySelector('form'), nomeEl = modal.querySelector('[data-ojf-avise-nome]'), okEl = modal.querySelector('[data-ojf-avise-ok]');
    var last = null, produtoAtual = '';
    var abrir = function(btn){
      last = btn; limpaErros(mform); modal.classList.remove('is-done');
      produtoAtual = btn.getAttribute('data-product-name') || '';
      nomeEl.textContent = produtoAtual;
      campo(mform,'product_id').value = btn.getAttribute('data-product-id') || '';
      campo(mform,'variation_id').value = btn.getAttribute('data-variation-id') || '0';
      preenche(mform);
      modal.hidden = false; requestAnimationFrame(function(){ modal.classList.add('is-open'); });
      document.documentElement.style.overflow = 'hidden';
      setTimeout(function(){ var n = campo(mform,'name'), em = campo(mform,'email'); (n.value ? (em.value ? campo(mform,'whatsapp') : em) : n).focus(); }, 60);
    };
    var fechar = function(){ modal.classList.remove('is-open'); modal.hidden = true; document.documentElement.style.overflow = ''; if (last) last.focus(); };
    document.addEventListener('click', function(e){
      var b = e.target.closest('[data-ojf-avise]');
      if (b){ e.preventDefault(); abrir(b); return; }
      if (e.target.closest('[data-ojf-avise-close]') || e.target === modal) fechar();
    });
    document.addEventListener('keydown', function(e){ if (e.key === 'Escape' && modal.classList.contains('is-open')) fechar(); });
    mform.addEventListener('submit', function(e){
      e.preventDefault();
      enviar(mform, produtoAtual, function(d, produto){ textoOk(okEl, d.email, produto); modal.classList.add('is-done'); });
    });
  }

  // Produto variável: o formulário aparece quando a opção escolhida não pode ser comprada.
  if (window.jQuery) {
    var caixas = function(form){ return jQuery('[data-ojf-avise-box].ojf-avise-box--variacao[data-product-id="' + (jQuery(form).attr('data-product_id') || '') + '"]'); };
    jQuery(document).on('found_variation', 'form.variations_form', function(e, v){
      var bx = caixas(this); if (!bx.length) return;
      if (v && (v.is_in_stock === false || v.is_purchasable === false)) {
        bx.each(function(){
          var nome = v.ojf_variation_title || '';
          this.setAttribute('data-product-name', nome);
          jQuery(this).find('[data-ojf-avise-nome]').text(nome || 'este produto');
          jQuery(this).find('input[name="variation_id"]').val(v.variation_id);
          this.classList.remove('is-done');
          this.hidden = false;
        });
      } else {
        bx.prop('hidden', true);
      }
    });
    jQuery(document).on('reset_data hide_variation', 'form.variations_form', function(){
      caixas(this).each(function(){
        this.hidden = true;
        this.setAttribute('data-product-name', '');
        jQuery(this).find('[data-ojf-avise-nome]').text('este produto');
        jQuery(this).find('input[name="variation_id"]').val('0');
      });
    });
  }
})();
</script>
    <?php
}, 50);

/* ── admin: WooCommerce → Avise-me ──────────────────────────────────────── */

add_action('admin_menu', function () {
    add_submenu_page('woocommerce', 'Avise-me', 'Avise-me', 'manage_woocommerce', 'ojf-avise-me', 'ojf_avise_admin_page');
}, 60);

function ojf_avise_admin_page() {
    global $wpdb;
    if (!current_user_can('manage_woocommerce')) return;
    $t = ojf_avise_table();

    if (isset($_POST['ojf_avise_teste']) && check_admin_referer('ojf_avise_teste')) {
        $para = sanitize_email(wp_unslash($_POST['email'] ?? ''));
        $pid  = (int) ($_POST['product_id'] ?? 0);
        $p    = $pid ? wc_get_product($pid) : null;
        if (!$p) { $ids = wc_get_products(['limit' => 1, 'status' => 'publish', 'stock_status' => 'instock', 'return' => 'ids']); $p = $ids ? wc_get_product($ids[0]) : null; }
        $ok = $p && is_email($para) && ojf_avise_send((object) ['product_id' => $p->get_id(), 'variation_id' => 0, 'email' => $para, 'name' => wp_get_current_user()->display_name]);
        echo '<div class="notice ' . ($ok ? 'notice-success' : 'notice-error') . ' is-dismissible"><p>' . ($ok ? 'E-mail de teste enviado para ' . esc_html($para) . '.' : 'Não foi possível enviar o teste (confira o e-mail e o produto).') . '</p></div>';
    }

    if (isset($_GET['csv']) && check_admin_referer('ojf_avise_csv')) { /* tratado em admin_init */ }

    $tot = $wpdb->get_results("SELECT status, COUNT(*) n FROM {$t} GROUP BY status", OBJECT_K);
    $top = $wpdb->get_results("SELECT product_id, variation_id, COUNT(*) n FROM {$t} WHERE status = 'waiting' GROUP BY product_id, variation_id ORDER BY n DESC LIMIT 15");
    $rows = $wpdb->get_results("SELECT * FROM {$t} ORDER BY id DESC LIMIT 200");
    $nome = function ($pid, $vid) { $o = wc_get_product($vid ?: $pid); return $o ? wp_strip_all_tags($o->get_name()) : '#' . ($vid ?: $pid); };

    echo '<div class="wrap"><h1>Avise-me</h1>';
    printf('<p>Esperando: <strong>%d</strong> &nbsp;·&nbsp; Avisados: <strong>%d</strong> &nbsp;·&nbsp; <a href="%s">Exportar CSV</a></p>',
        (int) ($tot['waiting']->n ?? 0), (int) ($tot['notified']->n ?? 0),
        esc_url(wp_nonce_url(admin_url('admin.php?page=ojf-avise-me&csv=1'), 'ojf_avise_csv')));

    echo '<h2>Mais esperados</h2><table class="widefat striped" style="max-width:900px"><thead><tr><th>Produto</th><th>Pessoas</th><th>Agora</th></tr></thead><tbody>';
    foreach ((array) $top as $r) {
        printf('<tr><td><a href="%s">%s</a></td><td>%d</td><td>%s</td></tr>', esc_url(get_edit_post_link($r->product_id)), esc_html($nome($r->product_id, $r->variation_id)), (int) $r->n,
            ojf_avise_item_in_stock($r->product_id, $r->variation_id) ? '<span style="color:#0f7a4a">em estoque</span>' : 'sem estoque');
    }
    if (!$top) echo '<tr><td colspan="3">Ninguém esperando.</td></tr>';
    echo '</tbody></table>';

    echo '<h2>Últimos pedidos</h2><table class="widefat striped"><thead><tr><th>Data</th><th>Produto</th><th>Nome</th><th>E-mail</th><th>WhatsApp</th><th>Situação</th></tr></thead><tbody>';
    foreach ((array) $rows as $r) {
        printf('<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
            esc_html(mysql2date('d/m/Y H:i', $r->created_at)), esc_html($nome($r->product_id, $r->variation_id)), esc_html($r->name), esc_html($r->email),
            esc_html($r->whatsapp), $r->status === 'notified' ? 'avisado em ' . esc_html(mysql2date('d/m/Y H:i', $r->notified_at)) : 'esperando');
    }
    if (!$rows) echo '<tr><td colspan="6">Nenhum pedido ainda.</td></tr>';
    echo '</tbody></table>';

    echo '<h2>Testar o e-mail</h2><form method="post">';
    wp_nonce_field('ojf_avise_teste');
    echo '<p><input type="email" name="email" placeholder="seu@email.com" required style="width:280px"> <input type="number" name="product_id" placeholder="ID do produto (opcional)" style="width:200px"> <button class="button button-primary" name="ojf_avise_teste" value="1">Enviar e-mail de teste</button></p></form></div>';
}

add_action('admin_init', function () {
    if (($_GET['page'] ?? '') !== 'ojf-avise-me' || empty($_GET['csv'])) return;
    if (!current_user_can('manage_woocommerce') || !check_admin_referer('ojf_avise_csv')) return;
    global $wpdb;
    $rows = $wpdb->get_results('SELECT * FROM ' . ojf_avise_table() . ' ORDER BY id DESC', ARRAY_A);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=avise-me-' . gmdate('Ymd') . '.csv');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['id', 'produto', 'variacao', 'nome', 'email', 'whatsapp', 'situacao', 'criado', 'avisado']);
    foreach ((array) $rows as $r) {
        fputcsv($out, [$r['id'], $r['product_id'], $r['variation_id'], $r['name'], $r['email'], $r['whatsapp'], $r['status'], $r['created_at'], $r['notified_at']]);
    }
    fclose($out);
    exit;
});
