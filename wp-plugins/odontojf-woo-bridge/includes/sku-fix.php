<?php
/**
 * Produtos sem SKU (>= 1.0.81): devolve um SKU a cada um, uma vez.
 *
 * Quase todos vieram de pares de páginas da origem com o mesmo código do ERP
 * (ver ojf_is_sibling_page em product-handler.php): a cada push um roubava o SKU
 * do outro, o Woo recusava ("SKU inválido ou duplicado") e os dois ficavam sem.
 *
 * SKU de volta = o código que o push gravou em _ojf_erp_code (o próprio _sku
 * pretendido: "OD-704" no pai variável, "10110" no simples); sem ele, no
 * variável, "OD-" + o código da 1ª variação. Se outro produto já tem esse SKU,
 * este fica com "<sku>-p<id>". Em ordem de id: o mais antigo fica com o limpo.
 */

if (!defined('ABSPATH')) exit;

define('OJF_SKUFIX_VERSION', 's1');

function ojf_skufix_pending() {
    return get_option('ojf_skufix_done') !== OJF_SKUFIX_VERSION;
}

/** Outro post (produto ou variação) já usa este SKU? */
function ojf_skufix_taken($sku, $pid) {
    global $wpdb;
    $outro = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT p.ID FROM {$wpdb->posts} p
         INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_sku'
         WHERE m.meta_value = %s AND p.ID <> %d AND p.post_type IN ('product','product_variation')
           AND p.post_status != 'trash' LIMIT 1", (string) $sku, (int) $pid
    ));
    if ($outro) return true;
    $id = function_exists('wc_get_product_id_by_sku') ? (int) wc_get_product_id_by_sku($sku) : 0;
    return $id && $id !== (int) $pid;
}

function ojf_skufix_step() {
    global $wpdb;
    $ids = $wpdb->get_col(
        "SELECT p.ID FROM {$wpdb->posts} p
         LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_sku'
         WHERE p.post_type = 'product' AND p.post_status IN ('publish','draft','private','pending')
           AND (m.meta_value IS NULL OR m.meta_value = '')
         ORDER BY p.ID ASC LIMIT 200"
    );
    $log = [];
    foreach ((array) $ids as $pid) {
        $pid = (int) $pid;
        $p = wc_get_product($pid);
        if (!$p) continue;
        $base = trim((string) $p->get_meta('_ojf_erp_code'));
        if ($base === '' && $p->is_type('variable')) {
            foreach ($p->get_children() as $vid) {
                $c = trim((string) get_post_meta($vid, '_ojf_erp_code', true));
                if ($c !== '') { $base = 'OD-' . $c; break; }
            }
        }
        if ($base === '') { $log[$pid] = 'sem código para usar'; continue; }
        $sku = ojf_skufix_taken($base, $pid) ? $base . '-p' . $pid : $base;
        try {
            $p->set_sku($sku);
            $p->save();
            $log[$pid] = $sku;
        } catch (\Throwable $e) {
            $log[$pid] = 'erro: ' . $e->getMessage();
        }
    }
    update_option('ojf_skufix_log', ['version' => OJF_SKUFIX_VERSION, 'at' => current_time('mysql'), 'items' => $log], false);
    update_option('ojf_skufix_done', OJF_SKUFIX_VERSION, false);
    error_log('[ojf] SKU devolvido a produtos sem SKU: ' . wp_json_encode($log));
}

ojf_job_register('skufix', 'ojf_skufix_pending', 'ojf_skufix_step', 1);

add_action('rest_api_init', function () {
    register_rest_route('odontojf/v1', '/sku-fix-status', [
        'methods' => 'GET', 'permission_callback' => '__return_true',
        'callback' => function () {
            return new WP_REST_Response([
                'done' => get_option('ojf_skufix_done') ?: null,
                'log'  => get_option('ojf_skufix_log') ?: null,
            ], 200);
        },
    ]);
});
