<?php
/**
 * Marca no fim do título (>= 1.0.69): "Nome do produto - MARCA".
 *
 * A busca do WordPress procura no título, não nos atributos: "6B" não achava
 * nada da 6B INVENT, porque a marca só existia no atributo "marca". Com a marca
 * no título, a busca, o Google e a listagem passam a mostrá-la.
 *
 * A regra vive aqui, no momento em que o título é gravado — tanto no push do
 * Worker quanto no lote que corrige os produtos que já existem. Se ela ficasse
 * só num rename avulso, o próximo push devolveria o nome sem a marca.
 *
 * - O nome que veio da origem fica em `_ojf_title_base`. O título é sempre
 *   derivado dele, então desligar a opção devolve o nome original.
 * - Nome que já cita a marca não ganha a marca de novo. "Cita" = a marca inteira
 *   ("BIO-ART" em "Placa Bio-Art") ou a primeira palavra dela, se tiver 3+
 *   letras ou um dígito ("ANGELUS" em "BROCA 699 ANGELUS" com marca "ANGELUS
 *   PRIMA"; "6B" com marca "6B INVENT"). "KG" de "KG SORENSEN" não conta: é peso.
 * - Marca com caractere quebrado vindo do ERP ("FLEXINOX/A�ONOX") não vai para
 *   o título.
 * - O slug (URL) não muda.
 */

if (!defined('ABSPATH')) exit;

define('OJF_TITLE_BRAND_SEP', ' - ');
define('OJF_TITLE_BRAND_RULE', '1'); // muda se a regra mudar → o lote roda de novo
define('OJF_TITLE_BRAND_BATCH', 25);

function ojf_title_brand_enabled() {
    return get_option('ojf_title_brand_suffix', '1') === '1';
}

/** minúsculas, sem acento, só letras/dígitos separados por espaço */
function ojf_brand_norm($s) {
    $s = html_entity_decode((string) $s, ENT_QUOTES, 'UTF-8');
    $s = function_exists('remove_accents') ? remove_accents($s) : $s;
    $s = strtolower($s);
    $s = preg_replace('/[^a-z0-9]+/', ' ', $s);
    return trim((string) $s);
}

/** O nome já cita a marca? */
function ojf_name_mentions_brand($name, $brand) {
    $n = ' ' . ojf_brand_norm($name) . ' ';
    $b = ojf_brand_norm($brand);
    if ($b === '') return true;
    if (strpos($n, ' ' . $b . ' ') !== false) return true;
    $first = explode(' ', $b)[0];
    $significativa = strlen($first) >= 3 || preg_match('/\d/', $first);
    return $significativa && strpos($n, ' ' . $first . ' ') !== false;
}

/** Marca que dá para pôr no título: não vazia e sem caractere quebrado. */
function ojf_brand_usable($brand) {
    $brand = trim((string) $brand);
    return $brand !== '' && strpos($brand, "\u{FFFD}") === false;
}

/** "Nome - MARCA", ou o nome como está se já cita a marca / marca inservível. */
function ojf_compose_title($base, $brand) {
    $base = trim((string) $base);
    $brand = trim((string) $brand);
    if ($base === '' || !ojf_brand_usable($brand) || ojf_name_mentions_brand($base, $brand)) return $base;
    return $base . OJF_TITLE_BRAND_SEP . $brand;
}

/** Título que o produto deve ter agora, com a opção ligada ou desligada. */
function ojf_target_title($base, $brand) {
    return ojf_title_brand_enabled() ? ojf_compose_title($base, $brand) : trim((string) $base);
}

/**
 * Título próprio de uma variação (`_odontojf_variation_title`, da origem) com a
 * marca do pai no fim. Sem isso, escolher a variação na página trocava o H1
 * "Resina Grandioso - VOCO" por "Resina Grandioso A1", sem a marca.
 */
function ojf_variation_title_with_brand($own, $parent_id) {
    $own = trim((string) $own);
    if ($own === '' || !ojf_title_brand_enabled()) return $own;
    return ojf_compose_title($own, (string) get_post_meta((int) $parent_id, '_odontojf_brand', true));
}

/** Marca do payload do Worker: atributo "Marca" ou meta _odontojf_brand. */
function ojf_payload_brand($data) {
    foreach ((array) ($data['attributes'] ?? []) as $a) {
        if (strtolower(trim((string) ($a['name'] ?? ''))) === 'marca') {
            $opts = array_values((array) ($a['options'] ?? []));
            if (!empty($opts[0]) && !is_array($opts[0])) return trim((string) $opts[0]);
        }
    }
    foreach ((array) ($data['meta_data'] ?? []) as $m) {
        if (($m['key'] ?? '') === '_odontojf_brand' && !empty($m['value'])) return trim((string) $m['value']);
    }
    return '';
}

/** Marca de um produto salvo: meta _odontojf_brand ou atributo "marca". */
function ojf_product_brand($product) {
    $b = trim((string) $product->get_meta('_odontojf_brand', true));
    if ($b !== '') return $b;
    foreach ($product->get_attributes() as $key => $attr) {
        if (!($attr instanceof WC_Product_Attribute) || $attr->is_taxonomy()) continue;
        if (sanitize_title($attr->get_name()) === 'marca' || $key === 'marca') {
            $opts = $attr->get_options();
            if (!empty($opts[0])) return trim((string) $opts[0]);
        }
    }
    return '';
}

/** Grava o título de um push do Worker: base no meta, marca no fim. */
function ojf_set_product_title($product, $data) {
    $base = trim((string) $data['name']);
    // "�" = texto que alguém decodificou errado no caminho (o ERP manda Latin-1).
    // Nunca publica isso: mantém o título que o produto já tem.
    if ($base === '' || strpos($base, "\u{FFFD}") !== false) return;
    $product->update_meta_data('_ojf_title_base', $base);
    $product->set_name(ojf_target_title($base, ojf_payload_brand($data)));
}

/* ── lote: aplica a regra aos produtos que já existem ───────────────────── */

/** Revisão alvo: regra + liga/desliga. Mudou → o lote recomeça do zero. */
function ojf_title_brand_target_rev() {
    return OJF_TITLE_BRAND_RULE . (ojf_title_brand_enabled() ? '-on' : '-off');
}

function ojf_title_brand_state() {
    $s = get_option('ojf_title_brand_state', []);
    return is_array($s) ? $s : [];
}

add_filter('cron_schedules', function ($s) {
    if (!isset($s['ojf_tb_1min'])) $s['ojf_tb_1min'] = ['interval' => 60, 'display' => 'OdontoJF título+marca 1min'];
    return $s;
});

add_action('init', function () {
    $st = ojf_title_brand_state();
    if (($st['rev'] ?? '') !== ojf_title_brand_target_rev()) {
        update_option('ojf_title_brand_state', [
            'rev' => ojf_title_brand_target_rev(), 'cursor' => 0, 'done' => false,
            'started' => current_time('mysql'), 'finished' => null,
            'changed' => 0, 'kept' => 0, 'no_brand' => 0, 'bad_brand' => 0, 'manual' => 0,
        ], false);
        $st = ojf_title_brand_state();
    }
    if (empty($st['done']) && !wp_next_scheduled('ojf_title_brand_cron')) {
        wp_schedule_event(time() + 30, 'ojf_tb_1min', 'ojf_title_brand_cron');
    } elseif (!empty($st['done']) && ($ts = wp_next_scheduled('ojf_title_brand_cron'))) {
        wp_unschedule_event($ts, 'ojf_title_brand_cron');
    }
}, 31);

add_action('ojf_title_brand_cron', 'ojf_title_brand_run_batch');

/*
 * Nesta loja o WP-Cron está desligado (DISABLE_WP_CRON) e nada de fora chama o
 * wp-cron.php: o evento acima fica agendado e nunca roda. Carona em requisição
 * também não basta — quase tudo sai do cache do LiteSpeed sem tocar o PHP
 * (medido: 2,5 produtos/min). Então o lote se encadeia sozinho, como a fila da
 * API: cada lote termina disparando o próximo por um admin-ajax interno não
 * bloqueante, com uma pausa entre lotes. Qualquer requisição que chegue ao PHP
 * religa a corrente se ela tiver morrido (fica viva por um transient de 3 min).
 */
define('OJF_TITLE_BRAND_PAUSE', 15); // segundos entre lotes

function ojf_title_brand_token() {
    return wp_hash('ojf_title_brand_tick');
}

/** Dispara o próximo elo. $chain vazio = começa uma corrente nova. */
function ojf_title_brand_dispatch($chain = '') {
    $chain = $chain !== '' ? $chain : strtolower(wp_generate_password(12, false)); // sanitize_key() no handler deixa em minúsculas
    set_transient('ojf_title_brand_chain', $chain, 3 * MINUTE_IN_SECONDS);
    $r = wp_remote_post(admin_url('admin-ajax.php'), [
        'timeout' => 0.01, 'blocking' => false, 'sslverify' => false,
        'body' => ['action' => 'ojf_title_brand_tick', 'token' => ojf_title_brand_token(), 'chain' => $chain],
    ]);
    if (is_wp_error($r)) {
        delete_transient('ojf_title_brand_chain');
        error_log('[ojf] título+marca: falha ao disparar o lote: ' . $r->get_error_message());
    }
}

function ojf_title_brand_pending() {
    $st = ojf_title_brand_state();
    return empty($st['done']) && ($st['rev'] ?? '') === ojf_title_brand_target_rev();
}

function ojf_title_brand_tick_handler() {
    if (!hash_equals(ojf_title_brand_token(), (string) ($_POST['token'] ?? ''))) {
        status_header(403);
        exit;
    }
    $chain = sanitize_key((string) ($_POST['chain'] ?? ''));
    // só uma corrente viva: um elo de corrente antiga (ou duplicada) para aqui
    if ($chain === '' || get_transient('ojf_title_brand_chain') !== $chain) exit;

    ignore_user_abort(true);
    if (function_exists('set_time_limit')) @set_time_limit(180);
    if (function_exists('litespeed_finish_request')) litespeed_finish_request();
    elseif (function_exists('fastcgi_finish_request')) fastcgi_finish_request();

    set_transient('ojf_title_brand_chain', $chain, 3 * MINUTE_IN_SECONDS);
    sleep(OJF_TITLE_BRAND_PAUSE);
    if (get_transient('ojf_title_brand_chain') !== $chain) exit;
    ojf_title_brand_run_batch();
    if (ojf_title_brand_pending()) ojf_title_brand_dispatch($chain);
    else delete_transient('ojf_title_brand_chain');
    exit;
}
add_action('wp_ajax_ojf_title_brand_tick', 'ojf_title_brand_tick_handler');
add_action('wp_ajax_nopriv_ojf_title_brand_tick', 'ojf_title_brand_tick_handler');

add_action('init', function () {
    if (wp_doing_cron() || (defined('WP_CLI') && WP_CLI)) return;
    if (wp_doing_ajax() && ($_REQUEST['action'] ?? '') === 'ojf_title_brand_tick') return;
    if (!ojf_title_brand_pending() || get_transient('ojf_title_brand_chain')) return;
    if (get_transient('ojf_title_brand_kick')) return; // no máximo uma tentativa por minuto
    set_transient('ojf_title_brand_kick', 1, 60);
    add_action('shutdown', function () { ojf_title_brand_dispatch(); }, 99);
}, 32);

/** Um lote de produtos. Seguro rodar de novo: só grava quando o título muda. */
function ojf_title_brand_run_batch() {
    global $wpdb;
    if (get_transient('ojf_title_brand_lock')) return;
    set_transient('ojf_title_brand_lock', 1, 5 * MINUTE_IN_SECONDS);
    try {
        $st = ojf_title_brand_state();
        if (!empty($st['done'])) return;
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts}
              WHERE post_type = 'product' AND post_status NOT IN ('trash','auto-draft') AND ID > %d
              ORDER BY ID ASC LIMIT %d",
            (int) ($st['cursor'] ?? 0), OJF_TITLE_BRAND_BATCH
        ));
        foreach ($ids as $id) {
            $id = (int) $id;
            $st['cursor'] = $id;
            $product = wc_get_product($id);
            if (!$product) continue;
            $brand = ojf_product_brand($product);
            $atual = (string) $product->get_name('edit');
            $base  = trim((string) $product->get_meta('_ojf_title_base', true));
            if ($base === '') {
                $base = $atual;
            } elseif ($atual !== $base && $atual !== ojf_compose_title($base, $brand)) {
                // alguém editou o título na mão depois do último push: respeita
                $base = $atual;
                $st['manual']++;
            }
            if ($brand === '') $st['no_brand']++;
            elseif (!ojf_brand_usable($brand)) $st['bad_brand']++;

            $alvo = ojf_target_title($base, $brand);
            if ($alvo === $atual) {
                // nada a gravar: não dispara o save (e os hooks dele) à toa
                $st['kept']++;
                continue;
            }
            $product->update_meta_data('_ojf_title_base', $base);
            $product->set_name($alvo); // variável: o Woo renomeia as variações junto
            $product->save();
            $st['changed']++;
        }
        if (count($ids) < OJF_TITLE_BRAND_BATCH) {
            $st['done'] = true;
            $st['finished'] = current_time('mysql');
            error_log(sprintf('[ojf] título+marca (%s) concluído: %d alterados, %d mantidos, %d sem marca, %d marca quebrada, %d editados à mão',
                $st['rev'], $st['changed'], $st['kept'], $st['no_brand'], $st['bad_brand'], $st['manual']));
        }
        update_option('ojf_title_brand_state', $st, false);
    } catch (\Throwable $e) {
        // guarda o erro no estado: sem isso uma exceção num produto travava o
        // lote em silêncio (o WP-Cron engole a saída)
        $st = ojf_title_brand_state();
        $st['last_error'] = sprintf('%s em #%d: %s', get_class($e), (int) ($id ?? 0), $e->getMessage());
        $st['cursor'] = isset($id) ? (int) $id : (int) ($st['cursor'] ?? 0); // pula o produto que quebrou
        update_option('ojf_title_brand_state', $st, false);
        error_log('[ojf] título+marca: ' . $st['last_error']);
    } finally {
        delete_transient('ojf_title_brand_lock');
    }
}

/* Estado do lote, sem dados sensíveis: para conferir de fora sem acesso ao admin. */
add_action('rest_api_init', function () {
    register_rest_route('odontojf/v1', '/title-brand-status', [
        'methods' => 'GET', 'permission_callback' => '__return_true',
        'callback' => function () {
            $st = ojf_title_brand_state();
            return new WP_REST_Response([
                'enabled'        => ojf_title_brand_enabled(),
                'state'          => $st,
                'next_run'       => ($t = wp_next_scheduled('ojf_title_brand_cron')) ? gmdate('c', $t) : null,
                'locked'         => (bool) get_transient('ojf_title_brand_lock'),
                'wp_cron_off'    => defined('DISABLE_WP_CRON') && DISABLE_WP_CRON,
                'now'            => gmdate('c'),
            ], 200);
        },
    ]);
});
