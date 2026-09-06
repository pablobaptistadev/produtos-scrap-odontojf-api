<?php
/**
 * OdontoJF Woo Bridge — metabox "Campos do produto (OdontoJF)".
 *
 * Os campos vêm do scrape/ERP via meta_data[] e ficam em metas com prefixo
 * `_` (protegido) → o WordPress ESCONDE eles da caixa nativa "Campos
 * personalizados". Este metabox os SURFA na página de edição do produto:
 * tabela curada (rótulo + valor formatado + meta_key) + dump cru de TODOS os
 * metas _odontojf_* / _ojf_* (nada fica escondido).
 *
 * Somente exibição (os valores são gerenciados pelo sync; editar aqui seria
 * sobrescrito no próximo update). Vídeos têm caixa própria editável (video.php).
 */

if (!defined('ABSPATH')) exit;

add_action('add_meta_boxes', function () {
    add_meta_box(
        'ojf_product_fields',
        'OdontoJF — Campos do produto (scrape + ERP)',
        'ojf_render_product_fields_box',
        'product',
        'normal',
        'default'
    );
});

/** Rótulos amigáveis dos campos curados (ordem de exibição). */
function ojf_product_fields_labels() {
    return [
        '_odontojf_sku'           => 'SKU / código na origem',
        '_ojf_erp_code'           => 'Código ERP',
        '_odontojf_brand'         => 'Marca',
        '_odontojf_provider_code' => 'Cód. do fornecedor (REF)',
        '_odontojf_barcode'       => 'Código de barras (EAN)',
        '_odontojf_peso_liquido'  => 'Peso líquido (kg)',
        '_odontojf_dimensoes'     => 'Dimensões / pesos',
        '_odontojf_installments'  => 'Parcelamento',
        '_odontojf_video_urls'    => 'Vídeos',
        '_odontojf_pdf_url'       => 'PDF',
        '_odontojf_source_url'    => 'URL na origem',
        '_odontojf_scrape_id'     => 'ID do scrape',
        '_ojf_last_erp_price'     => 'Último preço ERP consultado',
        '_ojf_price_history'      => 'Histórico de preço',
        '_seller'                 => 'Vendedor',
    ];
}

/** Renderiza um valor de meta conforme a chave (JSON, URL, lista…). */
function ojf_product_field_render_value($key, $value) {
    if ($value === '' || $value === null) return '<span style="color:#999;">—</span>';

    // URL clicável
    if (in_array($key, ['_odontojf_source_url', '_odontojf_pdf_url', '_odontojf_video_url'], true) && preg_match('#^https?://#i', (string) $value)) {
        return '<a href="' . esc_url($value) . '" target="_blank" rel="noopener" style="word-break:break-all;">' . esc_html($value) . ' ↗</a>';
    }

    // Dimensões: JSON objeto → lista
    if ($key === '_odontojf_dimensoes') {
        $d = json_decode((string) $value, true);
        if (is_array($d)) {
            $map = ['peso_bruto' => 'Peso bruto', 'peso_liquido' => 'Peso líquido', 'altura' => 'Altura', 'largura' => 'Largura', 'comprimento' => 'Comprimento'];
            $out = [];
            foreach ($map as $k => $lbl) {
                if (isset($d[$k]) && $d[$k] !== null && $d[$k] !== '') $out[] = '<strong>' . esc_html($lbl) . ':</strong> ' . esc_html((string) $d[$k]);
            }
            return $out ? implode(' &nbsp;·&nbsp; ', $out) : '<span style="color:#999;">—</span>';
        }
    }

    // Vídeos: JSON array → links
    if ($key === '_odontojf_video_urls') {
        $arr = json_decode((string) $value, true);
        if (is_array($arr) && $arr) {
            $li = array_map(function ($u) { return '<a href="' . esc_url($u) . '" target="_blank" rel="noopener">' . esc_html($u) . '</a>'; }, $arr);
            return implode('<br>', $li);
        }
    }

    // Histórico de preço: JSON → tabelinha
    if ($key === '_ojf_price_history') {
        $h = json_decode((string) $value, true);
        if (is_array($h) && $h) {
            $rows = '';
            foreach (array_slice(array_reverse($h), 0, 8) as $e) {
                $when = esc_html((string) ($e['ts'] ?? $e['date'] ?? ''));
                $price = esc_html((string) ($e['price'] ?? $e['preco'] ?? ''));
                $rows .= '<tr><td style="padding:2px 8px;color:#666;">' . $when . '</td><td style="padding:2px 8px;">R$ ' . $price . '</td></tr>';
            }
            return '<table style="border-collapse:collapse;font-size:12px;">' . $rows . '</table>';
        }
    }

    // JSON genérico → pretty
    $maybe = json_decode((string) $value, true);
    if (is_array($maybe)) {
        return '<code style="font-size:11px;white-space:pre-wrap;word-break:break-all;">' . esc_html(wp_json_encode($maybe, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . '</code>';
    }

    return esc_html((string) $value);
}

function ojf_render_product_fields_box($post) {
    $pid = (int) $post->ID;
    $labels = ojf_product_fields_labels();

    echo '<style>
        .ojf-pf{width:100%;border-collapse:collapse;font-size:13px;}
        .ojf-pf th{text-align:left;width:210px;padding:8px 10px;color:#1d2327;font-weight:600;vertical-align:top;border-bottom:1px solid #f0f0f1;}
        .ojf-pf td{padding:8px 10px;vertical-align:top;border-bottom:1px solid #f0f0f1;}
        .ojf-pf tr:hover{background:#fbfbfc;}
        .ojf-pf .mk{color:#888;font-family:ui-monospace,Menlo,monospace;font-size:11px;}
        .ojf-pf-note{color:#646970;font-size:12px;margin:0 0 10px;}
        .ojf-pf-raw summary{cursor:pointer;color:#2271b1;font-weight:600;margin-top:12px;}
        .ojf-pf-raw td{font-family:ui-monospace,Menlo,monospace;font-size:11px;word-break:break-all;}
    </style>';

    echo '<p class="ojf-pf-note">Campos vindos do <strong>scrape + ERP</strong> (somente leitura — geridos pelo sync). Os vídeos têm caixa própria editável abaixo.</p>';

    // ── tabela curada ──
    echo '<table class="ojf-pf"><tbody>';
    $shown = [];
    foreach ($labels as $key => $label) {
        // multivalor (ex.: pdf) → pega todos
        $all = get_post_meta($pid, $key, false);
        if (empty($all)) continue;
        $val_html = (count($all) > 1)
            ? implode('<br>', array_map(function ($v) use ($key) { return ojf_product_field_render_value($key, $v); }, $all))
            : ojf_product_field_render_value($key, $all[0]);
        echo '<tr><th>' . esc_html($label) . '<br><span class="mk">' . esc_html($key) . '</span></th><td>' . $val_html . '</td></tr>';
        $shown[$key] = true;
    }
    if (empty($shown)) {
        echo '<tr><td colspan="2" style="color:#999;">Nenhum campo OdontoJF gravado neste produto ainda.</td></tr>';
    }
    echo '</tbody></table>';

    // ── dump cru: TODOS os metas _odontojf_* / _ojf_* não exibidos acima ──
    global $wpdb;
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT meta_key, meta_value FROM {$wpdb->postmeta}
         WHERE post_id = %d AND (meta_key LIKE %s OR meta_key LIKE %s)
         ORDER BY meta_key ASC",
        $pid, $wpdb->esc_like('_odontojf_') . '%', $wpdb->esc_like('_ojf_') . '%'
    ));
    $extra = []; $body_imgs = 0;
    foreach ((array) $rows as $r) {
        if (strpos($r->meta_key, '_ojf_body_img_') === 0) { $body_imgs++; continue; } // resume
        if (isset($shown[$r->meta_key])) continue;
        $extra[] = $r;
    }
    echo '<details class="ojf-pf-raw"><summary>Ver TODOS os metas OdontoJF (cru)</summary>';
    echo '<table class="ojf-pf"><tbody>';
    if ($body_imgs > 0) {
        echo '<tr><th>Imagens do corpo no R2</th><td>' . (int) $body_imgs . ' imagem(ns) <span class="mk">_ojf_body_img_*</span></td></tr>';
    }
    foreach ($extra as $r) {
        $v = (string) $r->meta_value;
        if (mb_strlen($v) > 400) $v = mb_substr($v, 0, 400) . '…';
        echo '<tr><th><span class="mk">' . esc_html($r->meta_key) . '</span></th><td>' . esc_html($v) . '</td></tr>';
    }
    if (empty($extra) && $body_imgs === 0) echo '<tr><td colspan="2" style="color:#999;">Sem metas extras.</td></tr>';
    echo '</tbody></table></details>';
}
