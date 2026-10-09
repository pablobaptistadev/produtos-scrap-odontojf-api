<?php
/**
 * Disponibilidade = a da origem (>= 1.0.80).
 *
 * A loja não recebia estoque nenhum (o pipeline, no modo "preço da loja", omitia
 * preço E estoque): só 8 produtos apareciam esgotados, enquanto na origem eram
 * 664 simples e 1.952 variações. Daqui para frente o push manda a
 * disponibilidade; este lote aplica de uma vez o retrato atual da origem
 * (data/stock-sync.json, gerado do D1), sem esperar o rebuild diário.
 *
 * Só status (instock/outofstock), sem quantidade: o Woo só respeita o status
 * quando não gerencia estoque, então manage_stock vai para false. A trava real
 * de compra continua sendo a consulta ao ERP no carrinho.
 */

if (!defined('ABSPATH')) exit;

define('OJF_STOCKSYNC_FILE', OJF_BRIDGE_DIR . 'data/stock-sync.json');

function ojf_stocksync_data() {
    static $d = null;
    if ($d !== null) return $d;
    $d = [];
    if (is_readable(OJF_STOCKSYNC_FILE)) {
        $j = json_decode((string) file_get_contents(OJF_STOCKSYNC_FILE), true);
        if (is_array($j)) $d = $j;
    }
    return $d;
}

/** versão do arquivo, relida só quando ele muda */
function ojf_stocksync_version() {
    static $v = null;
    if ($v !== null) return $v;
    $mt = is_readable(OJF_STOCKSYNC_FILE) ? (int) filemtime(OJF_STOCKSYNC_FILE) : 0;
    $m  = get_option('ojf_stocksync_meta', []);
    if (!is_array($m) || ($m['mtime'] ?? -1) !== $mt) {
        $d = ojf_stocksync_data();
        $m = ['mtime' => $mt, 'version' => (string) ($d['version'] ?? '')];
        update_option('ojf_stocksync_meta', $m, true);
    }
    return $v = (string) $m['version'];
}

function ojf_stocksync_state() {
    $s = get_option('ojf_stocksync_state', []);
    return is_array($s) ? $s : [];
}

function ojf_stocksync_pending() {
    $v = ojf_stocksync_version();
    if ($v === '') return false;
    $st = ojf_stocksync_state();
    return ($st['version'] ?? '') !== $v || ($st['phase'] ?? '') !== 'done';
}

function ojf_stocksync_set_status($obj, $want) {
    if (!in_array($want, ['instock', 'outofstock'], true)) return false;
    if ($obj->get_stock_status() === $want && !$obj->get_manage_stock()) return false;
    $obj->set_manage_stock(false);
    $obj->set_stock_status($want);
    $obj->save();
    return true;
}

function ojf_stocksync_step() {
    $d  = ojf_stocksync_data();
    $st = ojf_stocksync_state();
    if (($st['version'] ?? '') !== ($d['version'] ?? '')) {
        $st = ['version' => $d['version'] ?? '', 'phase' => 'simple', 'cursor' => 0, 'started' => current_time('mysql'),
               'simple_changed' => 0, 'variation_changed' => 0, 'not_found' => 0, 'variation_not_found' => 0, 'last_error' => null];
    }
    try {
        if ($st['phase'] === 'simple') {
            $mapa = (array) ($d['simple'] ?? []);
            $ids  = array_keys($mapa);
            $i    = (int) $st['cursor'];
            foreach (array_slice($ids, $i, 60) as $pid) {
                $i++;
                $p = wc_get_product((int) $pid);
                if (!$p || $p->is_type('variable')) { $st['not_found']++; continue; }
                if (ojf_stocksync_set_status($p, (string) $mapa[$pid])) $st['simple_changed']++;
            }
            $st['cursor'] = $i;
            if ($i >= count($ids)) { $st['phase'] = 'variable'; $st['cursor'] = 0; }
        } elseif ($st['phase'] === 'variable') {
            $mapa = (array) ($d['variable'] ?? []);
            $ids  = array_keys($mapa);
            $i    = (int) $st['cursor'];
            foreach (array_slice($ids, $i, 20) as $pid) {
                $i++;
                $pai = wc_get_product((int) $pid);
                if (!$pai || !$pai->is_type('variable')) { $st['not_found']++; continue; }
                // código ERP da variação → id (o _sku pode ter sufixo -p<pai>)
                $por_codigo = [];
                foreach ($pai->get_children() as $vid) {
                    $cod = (string) get_post_meta($vid, '_ojf_erp_code', true);
                    if ($cod === '') $cod = (string) get_post_meta($vid, '_sku', true);
                    if ($cod !== '') $por_codigo[$cod] = (int) $vid;
                }
                $mudou = false;
                foreach ((array) $mapa[$pid] as $item) {
                    $vid = $por_codigo[(string) ($item['c'] ?? '')] ?? 0;
                    if (!$vid) { $st['variation_not_found']++; continue; }
                    $v = wc_get_product($vid);
                    if ($v && ojf_stocksync_set_status($v, (string) ($item['s'] ?? ''))) { $st['variation_changed']++; $mudou = true; }
                }
                if ($mudou) {
                    WC_Product_Variable::sync((int) $pid);
                    if (function_exists('wc_delete_product_transients')) wc_delete_product_transients((int) $pid);
                }
            }
            $st['cursor'] = $i;
            if ($i >= count($ids)) {
                $st['phase'] = 'done';
                $st['finished'] = current_time('mysql');
                if (function_exists('wc_recount_all_terms')) wc_recount_all_terms();
                do_action('litespeed_purge_all');
                error_log('[ojf] estoque espelhado da origem: ' . wp_json_encode($st));
            }
        }
    } catch (\Throwable $e) {
        $st['last_error'] = get_class($e) . ': ' . $e->getMessage();
        $st['cursor'] = (int) ($st['cursor'] ?? 0) + 1;
    }
    update_option('ojf_stocksync_state', $st, false);
}

ojf_job_register('stocksync', 'ojf_stocksync_pending', 'ojf_stocksync_step', 3);

add_action('rest_api_init', function () {
    register_rest_route('odontojf/v1', '/stock-sync-status', [
        'methods' => 'GET', 'permission_callback' => '__return_true',
        'callback' => function () {
            $d = ojf_stocksync_data();
            return new WP_REST_Response([
                'data_version' => $d['version'] ?? null,
                'simple_in_file' => count((array) ($d['simple'] ?? [])),
                'variable_in_file' => count((array) ($d['variable'] ?? [])),
                'state' => ojf_stocksync_state(),
                'chain' => (bool) get_transient('ojf_job_chain_stocksync'),
            ], 200);
        },
    ]);
});
