<?php
/**
 * OdontoJF Woo Bridge — log de consultas ERP + histórico de preços no produto.
 *
 * Custom fields (postmeta, JSON):
 *   _ojf_erp_cart_log  → cada consulta no add-to-cart: {ts, code, price, stock, approved, qty}
 *   _ojf_price_history → cada mudança de preço detectada: {ts, from, to, src}
 *   _ojf_last_erp_price→ último preço ERP visto (para detectar mudança)
 *
 * Exibição: metabox na página de edição do produto (admin) com o histórico.
 */

if (!defined('ABSPATH')) exit;

if (!defined('OJF_LOG_CAP')) define('OJF_LOG_CAP', 50); // máx. de entradas por log

function ojf_log_get($product_id, $meta_key) {
    $raw = get_post_meta((int) $product_id, $meta_key, true);
    if (is_array($raw)) return $raw;
    if (is_string($raw) && $raw !== '') { $d = json_decode($raw, true); return is_array($d) ? $d : []; }
    return [];
}

function ojf_log_append($product_id, $meta_key, $entry, $cap = OJF_LOG_CAP) {
    $product_id = (int) $product_id;
    if (!$product_id) return;
    $arr = ojf_log_get($product_id, $meta_key);
    $arr[] = $entry;
    if (count($arr) > $cap) $arr = array_slice($arr, -$cap);
    update_post_meta($product_id, $meta_key, wp_json_encode($arr, JSON_UNESCAPED_UNICODE));
}

/**
 * Registra UMA consulta ERP do carrinho.
 * $woo_price = preço do catálogo (o que estava no Woo ANTES) — para deixar claro
 * "era X". $erp_price = preço ao vivo do ERP ("virou Y"). Loga mudança quando o
 * ERP difere do último ERP visto.
 */
function ojf_log_cart_consult($product_id, $code, $woo_price, $erp_price, $stock, $approved, $qty) {
    $now = current_time('mysql');
    ojf_log_append($product_id, '_ojf_erp_cart_log', [
        'ts'        => $now,
        'code'      => (string) $code,
        'woo_price' => ($woo_price === '' ? null : ($woo_price === null ? null : (float) $woo_price)),
        'erp_price' => $erp_price,
        'stock'     => $stock,
        'approved'  => (bool) $approved,
        'qty'       => (int) $qty,
    ]);
    if ($erp_price !== null) {
        $last = get_post_meta($product_id, '_ojf_last_erp_price', true);
        if ($last !== '' && (float) $last !== (float) $erp_price) {
            ojf_log_append($product_id, '_ojf_price_history', [
                'ts' => $now, 'from' => (float) $last, 'to' => (float) $erp_price, 'src' => 'carrinho',
            ]);
        }
        update_post_meta($product_id, '_ojf_last_erp_price', (float) $erp_price);
    }
}

/** Registra mudança de preço detectada no sync (cadastro/update via bridge). */
function ojf_log_price_change_sync($product_id, $from, $to) {
    if ($from === null || $to === null) return;
    if ((float) $from === (float) $to) return;
    ojf_log_append($product_id, '_ojf_price_history', [
        'ts' => current_time('mysql'), 'from' => (float) $from, 'to' => (float) $to, 'src' => 'sync',
    ]);
    update_post_meta($product_id, '_ojf_last_erp_price', (float) $to);
}

/* ── Metabox na página de edição do produto ──────────────────────────────── */
add_action('add_meta_boxes', function () {
    add_meta_box('ojf_price_history', '📈 OdontoJF — Histórico de Preços & Consultas ERP', 'ojf_render_log_metabox', 'product', 'normal', 'default');
});

function ojf_render_log_metabox($post) {
    $pid = (int) $post->ID;
    $product = wc_get_product($pid);
    $ids = [$pid];
    if ($product && $product->is_type('variable')) $ids = array_merge($ids, $product->get_children());

    // histórico de preços (agregado, mais recente primeiro)
    $hist = [];
    foreach ($ids as $id) {
        foreach (ojf_log_get($id, '_ojf_price_history') as $h) { $h['_pid'] = $id; $hist[] = $h; }
    }
    usort($hist, function ($a, $b) { return strcmp((string) ($b['ts'] ?? ''), (string) ($a['ts'] ?? '')); });

    // consultas de carrinho (agregado, mais recente primeiro)
    $cart = [];
    foreach ($ids as $id) {
        foreach (ojf_log_get($id, '_ojf_erp_cart_log') as $c) { $c['_pid'] = $id; $cart[] = $c; }
    }
    usort($cart, function ($a, $b) { return strcmp((string) ($b['ts'] ?? ''), (string) ($a['ts'] ?? '')); });

    $fmt = function ($v) { return $v === null ? '—' : 'R$ ' . number_format((float) $v, 2, ',', '.'); };
    $code_label = function ($id) use ($pid) { return $id == $pid ? 'produto' : ('var #' . $id); };

    echo '<h3 style="margin:8px 0">Mudanças de preço (' . count($hist) . ')</h3>';
    if (empty($hist)) {
        echo '<p style="color:#888">Sem mudanças registradas ainda.</p>';
    } else {
        echo '<table class="widefat striped"><thead><tr><th>Data</th><th>Item</th><th>De</th><th>Para</th><th>Origem</th></tr></thead><tbody>';
        foreach (array_slice($hist, 0, 30) as $h) {
            $up = (float) ($h['to'] ?? 0) > (float) ($h['from'] ?? 0);
            $color = $up ? '#b32d2e' : '#1a7f37';
            echo '<tr><td>' . esc_html($h['ts'] ?? '') . '</td><td>' . esc_html($code_label($h['_pid'])) . '</td>';
            echo '<td>' . esc_html($fmt($h['from'] ?? null)) . '</td>';
            echo '<td style="color:' . $color . ';font-weight:600">' . esc_html($fmt($h['to'] ?? null)) . ($up ? ' ▲' : ' ▼') . '</td>';
            echo '<td>' . esc_html($h['src'] ?? '') . '</td></tr>';
        }
        echo '</tbody></table>';
    }

    echo '<h3 style="margin:16px 0 8px">Consultas ERP no carrinho (' . count($cart) . ')</h3>';
    echo '<p style="color:#666;margin:0 0 8px">"Preço Woo" = o que estava no catálogo. "Preço ERP" = o que o ERP retornou ao vivo (o que o cliente paga). Se forem diferentes, mostramos:  ▲/▼.</p>';
    if (empty($cart)) {
        echo '<p style="color:#888">Nenhuma consulta registrada ainda. (Acontece quando um cliente adiciona o produto ao carrinho.)</p>';
    } else {
        echo '<table class="widefat striped"><thead><tr><th>Data</th><th>Código ERP</th><th>Preço Woo (catálogo)</th><th>Preço ERP (ao vivo)</th><th>Δ</th><th>Estoque</th><th>Qtd</th><th>Aprovado</th></tr></thead><tbody>';
        foreach (array_slice($cart, 0, 30) as $c) {
            $ok = !empty($c['approved']);
            // compat com logs antigos (que só tinham 'price')
            $woo = array_key_exists('woo_price', $c) ? $c['woo_price'] : null;
            $erp = array_key_exists('erp_price', $c) ? $c['erp_price'] : ($c['price'] ?? null);
            $delta = '—';
            if ($woo !== null && $erp !== null) {
                $d = (float) $erp - (float) $woo;
                if (abs($d) < 0.005) $delta = '<span style="color:#1a7f37">igual</span>';
                elseif ($d > 0) $delta = '<span style="color:#b32d2e">▲ +' . esc_html(number_format($d, 2, ',', '.')) . '</span>';
                else $delta = '<span style="color:#1a7f37">▼ ' . esc_html(number_format($d, 2, ',', '.')) . '</span>';
            }
            echo '<tr><td>' . esc_html($c['ts'] ?? '') . '</td><td>' . esc_html($c['code'] ?? '') . '</td>';
            echo '<td>' . esc_html($fmt($woo)) . '</td>';
            echo '<td style="font-weight:600">' . esc_html($fmt($erp)) . '</td>';
            echo '<td>' . $delta . '</td>';
            echo '<td>' . esc_html($c['stock'] === null ? '—' : (string) $c['stock']) . '</td>';
            echo '<td>' . esc_html((string) ($c['qty'] ?? '')) . '</td>';
            echo '<td>' . ($ok ? '<span style="color:#1a7f37">✔ sim</span>' : '<span style="color:#b32d2e">✘ não</span>') . '</td></tr>';
        }
        echo '</tbody></table>';
    }
}
