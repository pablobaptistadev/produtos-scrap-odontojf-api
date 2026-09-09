<?php
/**
 * FullBAI Image Queue Dashboard
 *
 * Painel admin para monitorar a fila de processamento de imagens CDN.
 * Visual identico ao API Queue — dark theme Apple-style.
 *
 * INSTALAR: Copiar para wp-content/mu-plugins/ ou ativar como snippet.
 *
 * (arquivo INCLUÍDO pelo odontojf-woo-bridge.php — não é um plugin próprio)
 * Origem: FullBAI Image Queue Dashboard v2.1.0
 */

if (!defined('ABSPATH')) exit;

// ============================================================================
// MENU ADMIN
// ============================================================================

add_action('admin_menu', function() {
    add_submenu_page(
        'woocommerce',
        'Image Queue',
        'Image Queue',
        'manage_woocommerce',
        'fullbai-image-queue',
        'ojf_iq_render_page'
    );
});

function ojf_iq_attachment_state($attachment_id, $product_id = 0) {
    $attachment_id = (int) $attachment_id;
    $product_id = (int) $product_id;

    $attachment = $attachment_id ? get_post($attachment_id) : null;
    $state = [
        'attachment_exists' => (bool) $attachment,
        'attachment_id'     => $attachment_id ?: null,
        'attachment_url'    => $attachment_id ? wp_get_attachment_url($attachment_id) : '',
        'guid'              => $attachment ? (string) $attachment->guid : '',
        'seller_url_meta'   => $attachment_id ? get_post_meta($attachment_id, '_ojf_seller_url', true) : '',
        'cdn_pending'       => $attachment_id ? get_post_meta($attachment_id, '_ojf_cdn_pending', true) : '',
        'r2_object_key'     => $attachment_id ? get_post_meta($attachment_id, '_ojf_r2_object_key', true) : '',
        'attached_file'     => $attachment_id ? get_post_meta($attachment_id, '_wp_attached_file', true) : '',
        'meta'              => $attachment_id ? wp_get_attachment_metadata($attachment_id) : null,
        'product_exists'    => $product_id ? (bool) get_post($product_id) : false,
        'thumbnail_id'      => $product_id ? (int) get_post_thumbnail_id($product_id) : 0,
        'thumbnail_url'     => '',
        'gallery'           => [],
    ];

    if ($state['thumbnail_id']) {
        $state['thumbnail_url'] = wp_get_attachment_url($state['thumbnail_id']) ?: '';
    }

    if ($product_id) {
        $gallery_ids = array_filter(array_map('intval', explode(',', (string) get_post_meta($product_id, '_product_image_gallery', true))));
        foreach ($gallery_ids as $gid) {
            $state['gallery'][] = [
                'attachment_id' => $gid,
                'url' => wp_get_attachment_url($gid) ?: '',
            ];
        }
    }

    return $state;
}

// ============================================================================
// AJAX HANDLERS
// ============================================================================

// ── Stats ──
add_action('wp_ajax_ojf_iq_stats', function() {
    check_ajax_referer('ojf_iq_nonce', 'nonce');
    global $wpdb;
    $table = ojf_iq_table();
    $today = date('Y-m-d 00:00:00', current_time('timestamp'));

    $rows = $wpdb->get_results("SELECT status, COUNT(*) as c FROM `{$table}` GROUP BY status");
    $counts = ['pending' => 0, 'processing' => 0, 'completed' => 0, 'failed' => 0, 'passed' => 0];
    foreach ($rows as $r) if (isset($counts[$r->status])) $counts[$r->status] = (int) $r->c;

    $completed_today = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM `{$table}` WHERE status IN ('completed','passed') AND completed_at >= %s", $today
    ));

    $slots_busy = 0;
    for ($i = 1; $i <= OJF_IQ_CONCURRENCY; $i++) {
        $free = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT IS_FREE_LOCK(%s)", OJF_IQ_LOCK_NAME . '_' . $i
        ));
        if (!$free) $slots_busy++;
    }

    // Tamanhos — TOTAL (todas as imagens processadas). orig = valor INTEGRAL
    // (antes), opt = o que está no R2 (depois), saved = economia, percent = %.
    $sz = $wpdb->get_row(
        "SELECT COALESCE(SUM(CAST(original_size AS SIGNED)), 0) AS orig,
                COALESCE(SUM(CAST(optimized_size AS SIGNED)), 0) AS opt
         FROM `{$table}` WHERE status IN ('completed','passed')
         AND original_size > 0 AND optimized_size > 0"
    );
    $orig_bytes  = (int) ($sz->orig ?? 0);
    $opt_bytes   = (int) ($sz->opt ?? 0);          // o que está no R2 (otimizado)
    $saved_bytes = max(0, $orig_bytes - $opt_bytes);
    $percent     = $orig_bytes > 0 ? (int) round($saved_bytes / $orig_bytes * 100) : 0;
    $ojf_fmt = function ($b) {
        $b = (int) $b;
        if ($b >= 1073741824) return round($b / 1073741824, 2) . ' GB';
        if ($b >= 1048576)    return round($b / 1048576, 1) . ' MB';
        return $b > 0 ? round($b / 1024) . ' KB' : '0';
    };
    $saved_human = $ojf_fmt($saved_bytes);
    $orig_human  = $ojf_fmt($orig_bytes);                 // integral (antes)
    $r2_human    = $ojf_fmt($opt_bytes);                  // no R2 (depois)
    $r2_mb       = $opt_bytes > 0 ? round($opt_bytes / 1048576) . ' MB' : '0 MB';

    // Tempo médio — TOTAL (não só hoje)
    $avg_sec = $wpdb->get_var(
        "SELECT AVG(TIMESTAMPDIFF(SECOND, started_at, completed_at))
         FROM `{$table}` WHERE status='completed' AND started_at IS NOT NULL"
    );

    $next_scheduled = wp_next_scheduled('ojf_iq_cron');
    $last_worker_run = get_option('ojf_iq_last_worker_run', false);
    $last_trigger_get = get_option('ojf_iq_last_trigger_get', false);

    wp_send_json_success([
        'pending'         => $counts['pending'],
        'processing'      => $counts['processing'],
        'completed'       => $counts['completed'],
        'failed'          => $counts['failed'],
        'passed'          => $counts['passed'],
        'completed_today' => $completed_today,
        'slots_busy'      => $slots_busy,
        'slots_total'     => OJF_IQ_CONCURRENCY,
        'saved_bytes'     => $saved_bytes,
        'saved_human'     => $saved_human,
        'orig_human'      => $orig_human,   // valor INTEGRAL (antes)
        'r2_human'        => $r2_human,     // no R2 (otimizado)
        'r2_mb'           => $r2_mb,        // no R2 em MB
        'saved_percent'   => $percent,      // % economizado
        'avg_time'        => $avg_sec ? round($avg_sec, 1) : null,
        'last_worker_run' => $last_worker_run ?: 'never',
        'last_trigger_get'=> $last_trigger_get ?: 'never',
        'cron_next'       => $next_scheduled ? date('Y-m-d H:i:s', $next_scheduled) : false,
    ]);
});

// ── Recent items ──
add_action('wp_ajax_ojf_iq_recent', function() {
    check_ajax_referer('ojf_iq_nonce', 'nonce');
    global $wpdb;
    $table = ojf_iq_table();
    $today = isset($_POST['today']) && $_POST['today'] === '1';
    $date  = isset($_POST['date']) ? preg_replace('/[^0-9\-]/', '', (string) $_POST['date']) : '';
    $limit = isset($_POST['limit']) ? max(1, min(20000, (int) $_POST['limit'])) : 1500;

    // today=1 → só hoje | date=YYYY-MM-DD → aquele dia | senão → recentes (LIMIT)
    if ($today) {
        $where = 'WHERE DATE(created_at) = CURDATE()'; $lim = '';
    } elseif ($date !== '') {
        $where = $wpdb->prepare('WHERE DATE(created_at) = %s', $date); $lim = '';
    } else {
        $where = ''; $lim = 'LIMIT ' . (int) $limit;
    }
    $items = $wpdb->get_results(
        "SELECT id, attachment_id, product_id, seller_url, cdn_url, status,
                original_size, optimized_size, original_dims, final_dims, format,
                error, attempts, created_at, started_at, completed_at
         FROM `{$table}` {$where}
         ORDER BY FIELD(status, 'processing', 'pending', 'failed', 'completed', 'passed'), id DESC {$lim}"
    );
    // Nomes em LOTE (1 query) — o wc_get_product por linha travava com 1500+ linhas.
    $pids = array_values(array_unique(array_filter(array_map(function ($it) { return (int) $it->product_id; }, (array) $items))));
    $titles = [];
    if ($pids) {
        $in = implode(',', array_map('intval', $pids));
        foreach ($wpdb->get_results("SELECT ID, post_title FROM {$wpdb->posts} WHERE ID IN ($in)") as $r) {
            $titles[(int) $r->ID] = $r->post_title;
        }
    }
    foreach ((array) $items as $it) {
        $it->image_url = $it->cdn_url ?? '';
        $it->product_name = isset($titles[(int) $it->product_id]) && $titles[(int) $it->product_id] !== ''
            ? $titles[(int) $it->product_id] : ('#' . (int) $it->product_id);
    }
    wp_send_json_success(['items' => $items ?: [], 'total' => count((array) $items)]);
});

// ── Detalhe/log de um item ──
add_action('wp_ajax_ojf_iq_item_log', function() {
    check_ajax_referer('ojf_iq_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) wp_send_json_error('No permission');

    $id = isset($_POST['item_id']) ? (int) $_POST['item_id'] : 0;
    if ($id <= 0) wp_send_json_error('ID inválido');

    global $wpdb;
    $table = ojf_iq_table();

    $item = $wpdb->get_row($wpdb->prepare(
        "SELECT id, attachment_id, product_id, seller_url, cdn_url, status,
                original_size, optimized_size, original_dims, final_dims, format,
                error, attempts, created_at, started_at, completed_at
         FROM `{$table}` WHERE id = %d LIMIT 1",
        $id
    ), ARRAY_A);

    if (!$item) wp_send_json_error('Item não encontrado');

    $attachment_state = ojf_iq_attachment_state((int) $item['attachment_id'], (int) $item['product_id']);
    $signature = function_exists('ojf_dl_request_signature')
        ? ojf_dl_request_signature($item['seller_url'])
        : null;

    wp_send_json_success([
        'item' => [
            'id' => (int) $item['id'],
            'attachment_id' => (int) $item['attachment_id'],
            'product_id' => (int) $item['product_id'],
            'seller_url' => $item['seller_url'],
            'cdn_url' => $item['cdn_url'],
            'status' => $item['status'],
            'original_size' => $item['original_size'] !== null ? (int) $item['original_size'] : null,
            'optimized_size' => $item['optimized_size'] !== null ? (int) $item['optimized_size'] : null,
            'original_dims' => $item['original_dims'],
            'final_dims' => $item['final_dims'],
            'format' => $item['format'],
            'error' => $item['error'],
            'attempts' => (int) $item['attempts'],
            'created_at' => $item['created_at'],
            'started_at' => $item['started_at'],
            'completed_at' => $item['completed_at'],
        ],
        'item_raw' => $item,
        'request_signature' => $signature,
        'attachment_state' => $attachment_state,
    ]);
});

// ── Process Now ──
add_action('wp_ajax_ojf_iq_process_now', function() {
    check_ajax_referer('ojf_iq_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) wp_send_json_error('No permission');

    $before = ojf_iq_pending_count();

    if (!function_exists('ojf_iq_run_one_worker_now')) {
        wp_send_json_error('Image Queue core incompatível: ojf_iq_run_one_worker_now() não encontrada');
    }

    // One-shot: processa 1 item, libera o lock e dispara async para o resto.
    // NÃO usar ojf_iq_become_worker() aqui — ele chama fastcgi_finish_request()
    // antes do wp_send_json, o que faz o browser receber corpo vazio.
    $run = ojf_iq_run_one_worker_now();

    $after = $run['remaining_pending'] ?? ojf_iq_pending_count();
    wp_send_json_success([
        'processed'      => $before - $after,
        'pending_before' => $before,
        'pending_after'  => $after,
        'lock_acquired'  => !empty($run['lock_acquired']),
    ]);
});

// ── Retry Failed ──
add_action('wp_ajax_ojf_iq_retry', function() {
    check_ajax_referer('ojf_iq_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) wp_send_json_error('No permission');

    global $wpdb;
    $n = (int) $wpdb->query(
        "UPDATE `" . ojf_iq_table() . "` SET status='pending', started_at=NULL, error=NULL, attempts=0 WHERE status='failed'"
    );

    if ($n > 0 && function_exists('ojf_iq_become_worker')) {
        ojf_iq_become_worker();
    }

    wp_send_json_success(['retried' => $n]);
});

// ── Reprocess Single Item ──
add_action('wp_ajax_ojf_iq_reprocess', function() {
    check_ajax_referer('ojf_iq_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) wp_send_json_error('No permission');

    $id = isset($_POST['item_id']) ? (int) $_POST['item_id'] : 0;
    if ($id <= 0) wp_send_json_error('ID inválido');

    global $wpdb;
    $table = ojf_iq_table();

    $item = $wpdb->get_row($wpdb->prepare("SELECT * FROM `{$table}` WHERE id = %d", $id));
    if (!$item) wp_send_json_error('Item não encontrado');
    if ($item->status === 'processing') wp_send_json_error('Item está em processamento');

    $wpdb->update($table, [
        'status'         => 'pending',
        'cdn_url'        => null,
        'original_size'  => null,
        'optimized_size' => null,
        'original_dims'  => null,
        'final_dims'     => null,
        'format'         => null,
        'started_at'     => null,
        'completed_at'   => null,
        'error'          => null,
        'attempts'       => 0,
    ], ['id' => $id]);

    $run = function_exists('ojf_iq_run_one_worker_now')
        ? ojf_iq_run_one_worker_now($id)
        : ['processed' => false, 'lock_acquired' => false, 'remaining_pending' => ojf_iq_pending_count()];

    wp_send_json_success([
        'reprocessed' => $id,
        'attachment_id' => (int) $item->attachment_id,
        'processed_now' => !empty($run['processed']),
        'lock_acquired' => !empty($run['lock_acquired']),
        'remaining_pending' => $run['remaining_pending'] ?? ojf_iq_pending_count(),
    ]);
});

// ── Clear Completed ──
add_action('wp_ajax_ojf_iq_clear', function() {
    check_ajax_referer('ojf_iq_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) wp_send_json_error('No permission');

    global $wpdb;
    $n = (int) $wpdb->query("DELETE FROM `" . ojf_iq_table() . "` WHERE status IN ('completed','passed')");
    wp_send_json_success(['cleared' => $n]);
});

// ── Clear Failed ──
add_action('wp_ajax_ojf_iq_clear_failed', function() {
    check_ajax_referer('ojf_iq_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) wp_send_json_error('No permission');

    global $wpdb;
    $table = ojf_iq_table();
    // Converter failed com attachment deletado para passed
    $converted = (int) $wpdb->query(
        "UPDATE `{$table}` SET status='passed' WHERE status='failed' AND error LIKE '%não existe mais%'"
    );
    // Deletar os failed reais
    $deleted = (int) $wpdb->query("DELETE FROM `{$table}` WHERE status='failed'");
    wp_send_json_success(['converted' => $converted, 'deleted' => $deleted]);
});

// ============================================================================
// RENDER PAGE
// ============================================================================

function ojf_iq_render_page() {
    $nonce = wp_create_nonce('ojf_iq_nonce');
    $ajax  = admin_url('admin-ajax.php');
    $queue_enabled = defined('OJF_CDN_QUEUE_ENABLED') && OJF_CDN_QUEUE_ENABLED;
    ?>
    <div class="wrap" style="font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;background:#000;color:#f5f5f7;padding:40px 24px;margin-left:-20px;min-height:100vh;">
    <div style="max-width:1400px;margin:0 auto;">

        <h1 style="font-size:40px;font-weight:700;letter-spacing:-0.5px;margin:0 0 6px;">Image Queue</h1>
        <p style="color:#86868b;font-size:17px;margin:0 0 32px;">
            Fila de processamento de imagens CDN — resize 1200px + WebP
            <span style="display:inline-block;margin-left:12px;padding:3px 10px;border-radius:6px;font-size:12px;font-weight:600;<?php echo $queue_enabled ? 'background:#0d3b1e;color:#30d158;' : 'background:#3a2a00;color:#ff9f0a;'; ?>">
                <?php echo $queue_enabled ? 'QUEUE ATIVA' : 'QUEUE DESATIVADA'; ?>
            </span>
        </p>

        <!-- STATS -->
        <div id="stats" style="display:grid;grid-template-columns:repeat(7,1fr);gap:14px;margin-bottom:28px;">
            <div class="stat-card" data-filter="pending" style="background:#1d1d1f;padding:20px;border-radius:14px;border:1px solid #2d2d2d;cursor:pointer;transition:border-color .2s;">
                <div style="font-size:11px;color:#86868b;text-transform:uppercase;letter-spacing:0.5px;">Pending</div>
                <div id="s-pending" style="font-size:36px;font-weight:700;color:#ff9f0a;">0</div>
            </div>
            <div class="stat-card" data-filter="processing" style="background:#1d1d1f;padding:20px;border-radius:14px;border:1px solid #2d2d2d;cursor:pointer;transition:border-color .2s;">
                <div style="font-size:11px;color:#86868b;text-transform:uppercase;letter-spacing:0.5px;">Processing</div>
                <div id="s-processing" style="font-size:36px;font-weight:700;color:#0a84ff;">0</div>
            </div>
            <div class="stat-card" data-filter="completed" style="background:#1d1d1f;padding:20px;border-radius:14px;border:1px solid #2d2d2d;cursor:pointer;transition:border-color .2s;">
                <div style="font-size:11px;color:#86868b;text-transform:uppercase;letter-spacing:0.5px;">Concluído (total)</div>
                <div id="s-today" style="font-size:36px;font-weight:700;color:#30d158;">0</div>
            </div>
            <div class="stat-card" data-filter="failed" style="background:#1d1d1f;padding:20px;border-radius:14px;border:1px solid #2d2d2d;cursor:pointer;transition:border-color .2s;">
                <div style="font-size:11px;color:#86868b;text-transform:uppercase;letter-spacing:0.5px;">Failed</div>
                <div id="s-failed" style="font-size:36px;font-weight:700;color:#ff453a;">0</div>
            </div>
            <div class="stat-card" data-filter="passed" style="background:#1d1d1f;padding:20px;border-radius:14px;border:1px solid #2d2d2d;cursor:pointer;transition:border-color .2s;">
                <div style="font-size:11px;color:#86868b;text-transform:uppercase;letter-spacing:0.5px;">Passed</div>
                <div id="s-passed" style="font-size:36px;font-weight:700;color:#ff9500;">0</div>
            </div>
            <div style="background:#1d1d1f;padding:20px;border-radius:14px;border:1px solid #2d2d2d;">
                <div style="font-size:11px;color:#86868b;text-transform:uppercase;letter-spacing:0.5px;">Economizado</div>
                <div id="s-saved" style="font-size:32px;font-weight:700;color:#bf5af2;line-height:1.1;">&mdash;</div>
                <div id="s-saved-pct" style="font-size:12px;font-weight:600;color:#30d158;margin-top:2px;"></div>
                <div id="s-saved-orig" style="font-size:11px;color:#86868b;margin-top:4px;"></div>
                <div id="s-r2" style="font-size:11px;color:#0a84ff;margin-top:2px;font-weight:600;"></div>
            </div>
            <div style="background:#1d1d1f;padding:20px;border-radius:14px;border:1px solid #2d2d2d;">
                <div style="font-size:11px;color:#86868b;text-transform:uppercase;letter-spacing:0.5px;">Worker</div>
                <div id="s-worker" style="font-size:24px;font-weight:700;margin-top:6px;">&mdash;</div>
            </div>
        </div>

        <!-- BOTOES -->
        <div style="display:flex;gap:10px;margin-bottom:28px;flex-wrap:wrap;align-items:center;">
            <button onclick="processNow()" style="background:#0a84ff;border:none;color:#fff;padding:10px 22px;border-radius:980px;cursor:pointer;font-weight:600;font-size:14px;display:inline-flex;align-items:center;gap:6px;"><svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><polygon points="5,3 19,12 5,21"/></svg> Process Now</button>
            <button onclick="retryFailed()" style="background:#ff9f0a;border:none;color:#000;padding:10px 22px;border-radius:980px;cursor:pointer;font-weight:600;font-size:14px;display:inline-flex;align-items:center;gap:6px;"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M1 4v6h6"/><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"/></svg> Retry Failed</button>
            <button onclick="clearCompleted()" style="background:transparent;border:1px solid #3a3a3c;color:#f5f5f7;padding:10px 22px;border-radius:980px;cursor:pointer;font-weight:600;font-size:14px;display:inline-flex;align-items:center;gap:6px;"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg> Clear Completed</button>
            <button onclick="clearFailed()" style="background:transparent;border:1px solid #ff453a;color:#ff453a;padding:10px 22px;border-radius:980px;cursor:pointer;font-weight:600;font-size:14px;display:inline-flex;align-items:center;gap:6px;"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg> Clear Failed</button>
            <span id="filter-label" style="display:none;background:#2d2d2d;color:#f5f5f7;padding:6px 14px;border-radius:980px;font-size:13px;font-weight:600;cursor:pointer;" onclick="clearFilter()"></span>

            <span style="display:inline-flex;align-items:center;gap:8px;margin-left:auto;">
                <select id="seller-filter" onchange="setSellerFilter(this.value)" style="background:#1d1d1f;border:1px solid #3a3a3c;color:#f5f5f7;padding:5px 10px;border-radius:980px;cursor:pointer;font-weight:600;font-size:12px;outline:none;">
                    <option value="">Todos sellers</option>
                </select>
                <button id="date-filter-btn" onclick="toggleDateFilter()" title="Filtrar por data" style="background:transparent;border:1px solid #3a3a3c;color:#86868b;padding:5px 8px;border-radius:980px;cursor:pointer;display:inline-flex;align-items:center;transition:all .2s;" onmouseover="this.style.borderColor='#0a84ff';this.style.color='#0a84ff'" onmouseout="if(!window.__dateFilterActive){this.style.borderColor='#3a3a3c';this.style.color='#86868b'}">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                </button>
                <input id="date-filter-input" type="date" onchange="setDateFilter(this.value)" style="display:none;background:#1d1d1f;border:1px solid #3a3a3c;color:#f5f5f7;padding:5px 10px;border-radius:980px;font-size:12px;outline:none;">
                <span style="color:#86868b;font-size:12px;margin-left:4px;margin-right:4px;">Mostrar:</span>
                <button class="limit-btn active-limit" data-limit="100" onclick="setLimit(100)" style="background:#2d2d2d;border:1px solid #48484a;color:#f5f5f7;padding:5px 12px;border-radius:980px;cursor:pointer;font-weight:600;font-size:12px;">100</button>
                <button class="limit-btn" data-limit="500" onclick="setLimit(500)" style="background:transparent;border:1px solid #3a3a3c;color:#86868b;padding:5px 12px;border-radius:980px;cursor:pointer;font-weight:600;font-size:12px;">500</button>
                <button class="limit-btn" data-limit="1500" onclick="setLimit(1500)" style="background:transparent;border:1px solid #3a3a3c;color:#86868b;padding:5px 12px;border-radius:980px;cursor:pointer;font-weight:600;font-size:12px;">1500</button>
                <button class="limit-btn" data-limit="today" id="btn-today" onclick="loadToday()" style="background:transparent;border:1px solid #30d158;color:#30d158;padding:5px 14px;border-radius:980px;cursor:pointer;font-weight:600;font-size:12px;">Today</button>
                <button id="btn-history" onclick="loadHistory(5000)" title="Carrega todo o histórico (até 5000 mais recentes)" style="background:transparent;border:1px solid #bf5af2;color:#bf5af2;padding:5px 14px;border-radius:980px;cursor:pointer;font-weight:600;font-size:12px;">📜 Ver todo histórico</button>
            </span>
            <span id="msg" style="display:inline-flex;align-items:center;color:#86868b;font-size:13px;margin-left:8px;"></span>
        </div>

        <!-- TABELA -->
        <div style="background:#1d1d1f;border-radius:14px;border:1px solid #2d2d2d;overflow:hidden;">
            <div style="overflow-x:auto;max-height:800px;overflow-y:auto;">
                <table style="width:100%;border-collapse:collapse;font-size:13px;">
                    <thead>
                        <tr style="border-bottom:2px solid #2d2d2d;position:sticky;top:0;background:#1d1d1f;z-index:1;">
                            <th style="padding:12px 10px;text-align:left;color:#86868b;font-size:11px;text-transform:uppercase;">Preview</th>
                            <th style="padding:12px 10px;text-align:left;color:#86868b;font-size:11px;text-transform:uppercase;">Produto</th>
                            <th style="padding:12px 10px;text-align:left;color:#86868b;font-size:11px;text-transform:uppercase;">URL Origem</th>
                            <th style="padding:12px 10px;text-align:left;color:#86868b;font-size:11px;text-transform:uppercase;">Status</th>
                            <th style="padding:12px 10px;text-align:center;color:#86868b;font-size:11px;text-transform:uppercase;">Tamanho</th>
                            <th style="padding:12px 10px;text-align:center;color:#86868b;font-size:11px;text-transform:uppercase;">Formato</th>
                            <th style="padding:12px 10px;text-align:center;color:#86868b;font-size:11px;text-transform:uppercase;">Dims</th>
                            <th style="padding:12px 10px;text-align:center;color:#86868b;font-size:11px;text-transform:uppercase;">Tries</th>
                            <th style="padding:12px 10px;text-align:left;color:#86868b;font-size:11px;text-transform:uppercase;">Criado</th>
                            <th style="padding:12px 10px;text-align:left;color:#86868b;font-size:11px;text-transform:uppercase;">Concluido</th>
                            <th style="padding:12px 10px;text-align:center;color:#86868b;font-size:11px;text-transform:uppercase;">Duracao</th>
                            <th style="padding:12px 10px;text-align:center;color:#86868b;font-size:11px;text-transform:uppercase;">Ação</th>
                        </tr>
                    </thead>
                    <tbody id="tbl">
                        <tr><td colspan="12" style="text-align:center;color:#86868b;padding:40px;">Carregando...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div style="margin-top:16px;color:#48484a;font-size:12px;">
            Auto-refresh: stats 5s &middot; tabela 10s &middot; <span id="last-refresh">&mdash;</span>
        </div>

        <div style="margin-top:10px;padding:10px 14px;background:#1d1d1f;border:1px solid #2d2d2d;border-radius:10px;color:#86868b;font-size:12px;font-family:monospace;">
            <span id="iq-info-line">Last Run: &mdash; | Trigger GET: &mdash; | Cron: &mdash;</span>
        </div>

    </div>
    </div>

    <div id="iq-log-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.72);z-index:99999;align-items:center;justify-content:center;padding:24px;">
        <div style="width:min(1100px,96vw);max-height:92vh;background:#111214;border:1px solid #2d2d2d;border-radius:18px;box-shadow:0 30px 80px rgba(0,0,0,.55);overflow:hidden;display:flex;flex-direction:column;">
            <div style="display:flex;align-items:center;justify-content:space-between;padding:18px 22px;border-bottom:1px solid #2d2d2d;">
                <div>
                    <div style="font-size:18px;font-weight:700;color:#f5f5f7;">Log da imagem</div>
                    <div id="iq-log-subtitle" style="font-size:12px;color:#86868b;margin-top:4px;">Carregando...</div>
                </div>
                <button onclick="closeLogModal()" style="background:transparent;border:1px solid #3a3a3c;color:#f5f5f7;padding:8px 10px;border-radius:10px;cursor:pointer;font-weight:600;">Fechar</button>
            </div>
            <div id="iq-log-body" style="overflow:auto;padding:20px 22px;display:grid;grid-template-columns:1fr 1fr;gap:18px;">
                <div style="grid-column:1/-1;color:#86868b;">Carregando...</div>
            </div>
        </div>
    </div>

    <script>
    const N = '<?php echo $nonce; ?>';
    const U = '<?php echo $ajax; ?>';

    // Parser resiliente: se a resposta vier vazia ou truncada (ex.: timeout do nginx,
    // fastcgi_finish_request prematuro), NÃO quebra o auto-refresh — devolve
    // {success:false, data:'mensagem'} e a UI segue rodando.
    const post = (action, extra = {}) => {
        const p = new URLSearchParams({action, nonce: N, ...extra});
        return fetch(U, {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body: p})
            .then(r => r.text().then(t => ({status: r.status, text: t})))
            .then(({status, text}) => {
                if (!text) return {success: false, data: 'Resposta vazia (HTTP ' + status + ' — possível timeout)'};
                try { return JSON.parse(text); }
                catch(e) { return {success: false, data: 'Resposta inválida (HTTP ' + status + '): ' + text.substring(0, 120)}; }
            })
            .catch(e => ({success: false, data: 'Erro de rede: ' + (e && e.message ? e.message : e)}));
    };

    const statusColor = {completed:'#30d158', failed:'#ff453a', pending:'#ff9f0a', processing:'#0a84ff', passed:'#ff9500'};

    // SVG para imagem placeholder
    const svgImage = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#48484a" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>';
    const svgTimer = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="#0a84ff" stroke-width="2"><circle cx="12" cy="13" r="8"/><path d="M12 9v4l2 2"/><path d="M5 3L2 6"/><path d="M22 6l-3-3"/></svg>';
    const svgReprocess = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M1 4v6h6"/><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"/></svg>';
    const svgLog = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 17v.01"/><path d="M12 7a4 4 0 0 1 0 8"/><circle cx="12" cy="12" r="10"/></svg>';

    function fmtDateTime(dateStr) {
        if (!dateStr) return '\u2014';
        const d = new Date(dateStr.replace(' ', 'T'));
        const dd = String(d.getDate()).padStart(2,'0');
        const mm = String(d.getMonth()+1).padStart(2,'0');
        const hh = String(d.getHours()).padStart(2,'0');
        const mi = String(d.getMinutes()).padStart(2,'0');
        const ss = String(d.getSeconds()).padStart(2,'0');
        return dd+'/'+mm+' '+hh+':'+mi+':'+ss;
    }

    function fmtBytes(b) {
        if (!b || b <= 0) return '\u2014';
        if (b < 1024) return b + 'B';
        if (b < 1048576) return (b / 1024).toFixed(1) + 'KB';
        return (b / 1048576).toFixed(1) + 'MB';
    }

    function fmtSaved(bytes) {
        if (!bytes || bytes <= 0) return '\u2014';
        if (bytes < 1048576) return (bytes / 1024).toFixed(0) + 'KB';
        return (bytes / 1048576).toFixed(1) + 'MB';
    }

    function escapeHtml(value) {
        return String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function prettyJson(value) {
        if (value === null || value === undefined || value === '') return '<span style="color:#48484a;">vazio</span>';
        if (typeof value === 'string') return '<pre style="margin:0;white-space:pre-wrap;word-break:break-word;">'+escapeHtml(value)+'</pre>';
        return '<pre style="margin:0;white-space:pre-wrap;word-break:break-word;">'+escapeHtml(JSON.stringify(value, null, 2))+'</pre>';
    }

    function renderLogCard(title, content, fullWidth = false) {
        return '<section style="background:#17181c;border:1px solid #2d2d2d;border-radius:14px;padding:16px;'+(fullWidth?'grid-column:1/-1;':'')+'">'
            + '<div style="font-size:12px;font-weight:700;color:#f5f5f7;text-transform:uppercase;letter-spacing:.4px;margin-bottom:10px;">'+escapeHtml(title)+'</div>'
            + '<div style="font-size:12px;color:#c7c7cc;line-height:1.5;">'+content+'</div>'
            + '</section>';
    }

    function renderKeyValueRows(rows) {
        return rows.map(row => '<div style="display:flex;gap:10px;padding:6px 0;border-bottom:1px solid #222;"><div style="width:150px;color:#86868b;">'+escapeHtml(row.label)+'</div><div style="flex:1;color:#f5f5f7;">'+row.value+'</div></div>').join('');
    }

    function closeLogModal() {
        document.getElementById('iq-log-modal').style.display = 'none';
    }

    async function openLogModal(id) {
        const modal = document.getElementById('iq-log-modal');
        document.getElementById('iq-log-subtitle').textContent = 'Item #' + id;
        document.getElementById('iq-log-body').innerHTML = '<div style="grid-column:1/-1;color:#86868b;">Carregando item #' + id + '...</div>';
        modal.style.display = 'flex';

        const j = await post('ojf_iq_item_log', {item_id: id});
        if (!j.success) {
            document.getElementById('iq-log-body').innerHTML = '<div style="grid-column:1/-1;color:#ff453a;">Erro ao carregar o log.</div>';
            return;
        }

        const item = j.data.item || {};
        const attachmentState = j.data.attachment_state || {};
        const requestSignature = j.data.request_signature;

        document.getElementById('iq-log-subtitle').textContent = 'Item #' + item.id + ' · att:' + (item.attachment_id || '—') + ' · ' + (item.status || '');

        const summaryRows = renderKeyValueRows([
            {label: 'Produto', value: escapeHtml(item.product_id || '\u2014')},
            {label: 'Attachment', value: escapeHtml(item.attachment_id || '\u2014')},
            {label: 'Status', value: '<span style="color:'+(statusColor[item.status] || '#86868b')+';font-weight:600;">'+escapeHtml(item.status || '\u2014')+'</span>'},
            {label: 'Tentativas', value: escapeHtml(item.attempts || 0)},
            {label: 'Criado', value: escapeHtml(fmtDateTime(item.created_at))},
            {label: 'Concluído', value: escapeHtml(fmtDateTime(item.completed_at))},
            {label: 'Erro', value: item.error ? '<span style="color:#ff453a;">'+escapeHtml(item.error)+'</span>' : '<span style="color:#30d158;">sem erro</span>'},
        ]);

        const requestRows = requestSignature
            ? renderKeyValueRows([
                {label: 'Original URL', value: escapeHtml(requestSignature.original_url || '\u2014')},
                {label: 'Request URL', value: escapeHtml(requestSignature.request_url || '\u2014')},
                {label: 'User-Agent', value: escapeHtml(requestSignature.user_agent || '\u2014')},
                {label: 'Query', value: requestSignature.query_applied ? '<span style="color:#30d158;">ativa</span>' : '<span style="color:#86868b;">inativa</span>'},
                {label: 'Header', value: requestSignature.header_applied ? '<pre style="margin:0;white-space:pre-wrap;">'+escapeHtml(JSON.stringify(requestSignature.headers || {}, null, 2))+'</pre>' : '<span style="color:#86868b;">inativo</span>'},
            ])
            : '<span style="color:#48484a;">assinatura indisponível</span>';

        const attachmentRows = renderKeyValueRows([
            {label: 'Attachment existe', value: attachmentState.attachment_exists ? '<span style="color:#30d158;">sim</span>' : '<span style="color:#ff453a;">não</span>'},
            {label: 'Attachment URL', value: escapeHtml(attachmentState.attachment_url || '\u2014')},
            {label: 'GUID', value: escapeHtml(attachmentState.guid || '\u2014')},
            {label: 'Seller URL meta', value: escapeHtml(attachmentState.seller_url_meta || '\u2014')},
            {label: 'R2 object key', value: escapeHtml(attachmentState.r2_object_key || '\u2014')},
            {label: '_wp_attached_file', value: escapeHtml(attachmentState.attached_file || '\u2014')},
            {label: 'CDN pending', value: attachmentState.cdn_pending ? escapeHtml(attachmentState.cdn_pending) : '<span style="color:#48484a;">vazio</span>'},
        ]);

        const galleryRows = (attachmentState.gallery || []).map((img, idx) =>
            '<div style="padding:5px 0;border-bottom:1px solid #222;">#'+(idx+1)+' · att:'+escapeHtml(img.attachment_id)+' · '+escapeHtml(img.url || '')+'</div>'
        ).join('') || '<span style="color:#48484a;">galeria vazia</span>';

        const productState =
            renderKeyValueRows([
                {label: 'Produto existe', value: attachmentState.product_exists ? '<span style="color:#30d158;">sim</span>' : '<span style="color:#ff453a;">não</span>'},
                {label: 'Thumbnail ID', value: escapeHtml(attachmentState.thumbnail_id || '\u2014')},
                {label: 'Thumbnail URL', value: escapeHtml(attachmentState.thumbnail_url || '\u2014')},
            ])
            + '<div style="margin-top:10px;color:#86868b;">Galeria atual</div>'
            + '<div style="margin-top:6px;">'+galleryRows+'</div>';

        document.getElementById('iq-log-body').innerHTML =
            renderLogCard('Resumo', summaryRows)
            + renderLogCard('Assinatura do request', requestRows)
            + renderLogCard('Estado do attachment', attachmentRows, true)
            + renderLogCard('Estado atual do produto', productState, true)
            + renderLogCard('Item JSON', prettyJson(item), true)
            + renderLogCard('Linha bruta', prettyJson(j.data.item_raw), true)
            + renderLogCard('Attachment metadata', prettyJson(attachmentState.meta), true);
    }

    // ── Filtro por status / limite / seller / data ──
    let activeFilter = null;
    let allItems = [];
    let displayLimit = 100;
    let todayMode = false;
    let sellerFilter = '';
    let dateFilter = '';
    window.__dateFilterActive = false;

    function setLimit(n) {
        // RE-BUSCA no backend (antes só mudava o display do que já estava carregado,
        // por isso travava em 10 quando vinha do modo Today).
        loadHistory(n);
    }

    async function loadHistory(limit) {
        todayMode = false;
        dateFilter = '';
        window.__dateFilterActive = false;
        var di = document.getElementById('date-filter-input'); if (di) di.value = '';
        var db = document.getElementById('date-filter-btn'); if (db) { db.style.borderColor = '#3a3a3c'; db.style.color = '#86868b'; }
        displayLimit = limit || 1500;
        updateLimitButtons();
        document.getElementById('msg').textContent = 'Carregando histórico...';
        const j = await post('ojf_iq_recent', { limit: String(limit || 1500) });
        if (j.success) {
            allItems = j.data.items || [];
            refreshSellerOptions();
            document.getElementById('msg').textContent = allItems.length + ' itens (histórico completo)';
            renderItems();
            document.getElementById('last-refresh').textContent = new Date().toLocaleTimeString();
        }
    }

    async function loadToday() {
        todayMode = true;
        displayLimit = 99999;
        updateLimitButtons();
        document.getElementById('msg').textContent = 'Carregando todos de hoje...';
        const j = await post('ojf_iq_recent', {today: '1'});
        if (j.success) {
            allItems = j.data.items || [];
            refreshSellerOptions();
            document.getElementById('msg').textContent = allItems.length + ' itens de hoje';
            renderItems();
            document.getElementById('last-refresh').textContent = new Date().toLocaleTimeString();
        }
    }

    function updateLimitButtons() {
        document.querySelectorAll('.limit-btn').forEach(btn => {
            const isActive = todayMode ? btn.dataset.limit === 'today' : parseInt(btn.dataset.limit) === displayLimit;
            btn.style.background = isActive ? '#2d2d2d' : 'transparent';
            btn.style.color = btn.dataset.limit === 'today' ? '#30d158' : (isActive ? '#f5f5f7' : '#86868b');
            btn.style.borderColor = btn.dataset.limit === 'today' ? '#30d158' : (isActive ? '#48484a' : '#3a3a3c');
        });
    }

    function setSellerFilter(v) {
        sellerFilter = v || '';
        renderItems();
    }

    function toggleDateFilter() {
        const inp = document.getElementById('date-filter-input');
        if (inp.style.display === 'none') {
            inp.style.display = 'inline-flex';
            inp.focus();
            try { inp.showPicker && inp.showPicker(); } catch(e) {}
        } else if (!inp.value) {
            inp.style.display = 'none';
        }
    }

    async function setDateFilter(v) {
        dateFilter = v || '';
        const btn = document.getElementById('date-filter-btn');
        if (dateFilter) {
            // BUSCA aquele dia no backend (antes filtrava só o que já estava na tela).
            window.__dateFilterActive = true;
            btn.style.borderColor = '#0a84ff';
            btn.style.color = '#0a84ff';
            todayMode = false;
            displayLimit = 99999;
            document.getElementById('msg').textContent = 'Carregando ' + dateFilter + '...';
            const j = await post('ojf_iq_recent', { date: dateFilter });
            if (j.success) {
                allItems = j.data.items || [];
                refreshSellerOptions();
                document.getElementById('msg').textContent = allItems.length + ' itens em ' + dateFilter;
                renderItems();
            }
        } else {
            window.__dateFilterActive = false;
            btn.style.borderColor = '#3a3a3c';
            btn.style.color = '#86868b';
            document.getElementById('date-filter-input').style.display = 'none';
            loadHistory(displayLimit > 5000 ? 1500 : displayLimit);
        }
    }

    function refreshSellerOptions() {
        const sel = document.getElementById('seller-filter');
        if (!sel) return;
        const sellers = Array.from(new Set(allItems.map(i => i.seller).filter(Boolean))).sort();
        const current = sel.value;
        sel.innerHTML = '<option value="">Todos sellers</option>' +
            sellers.map(s => '<option value="'+escapeHtml(s)+'"'+(s===current?' selected':'')+'>'+escapeHtml(s)+'</option>').join('');
        if (current && !sellers.includes(current)) {
            sellerFilter = '';
        }
    }

    document.querySelectorAll('.stat-card').forEach(card => {
        card.addEventListener('click', function() {
            const f = this.dataset.filter;
            if (activeFilter === f) { clearFilter(); return; }
            activeFilter = f;
            document.querySelectorAll('.stat-card').forEach(c => c.style.borderColor = '#2d2d2d');
            this.style.borderColor = statusColor[f] || '#86868b';
            const label = document.getElementById('filter-label');
            label.style.display = 'inline-flex';
            label.innerHTML = f.toUpperCase() + ' <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" style="margin-left:6px;"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>';
            label.style.color = statusColor[f] || '#f5f5f7';
            renderItems();
        });
    });

    function clearFilter() {
        activeFilter = null;
        document.querySelectorAll('.stat-card').forEach(c => c.style.borderColor = '#2d2d2d');
        document.getElementById('filter-label').style.display = 'none';
        renderItems();
    }

    async function loadStats() {
        try {
            const j = await post('ojf_iq_stats');
            if (!j.success) return;
            const d = j.data;
            document.getElementById('s-pending').textContent = d.pending;
            document.getElementById('s-processing').textContent = d.processing;
            document.getElementById('s-today').textContent = d.completed;
            document.getElementById('s-failed').textContent = d.failed;
            document.getElementById('s-passed').textContent = d.passed || 0;
            document.getElementById('s-saved').textContent = (d.saved_human && d.saved_human !== '0') ? d.saved_human : fmtSaved(d.saved_bytes);
            var pctEl = document.getElementById('s-saved-pct');
            var origEl = document.getElementById('s-saved-orig');
            var r2El = document.getElementById('s-r2');
            if (pctEl) pctEl.textContent = (d.saved_percent != null ? '−' + d.saved_percent + '% menor' : '');
            if (origEl) origEl.textContent = (d.orig_human ? 'Integral: ' + d.orig_human : '');
            if (r2El) r2El.textContent = (d.r2_mb ? '📦 No R2: ' + d.r2_mb : '');

            const lr = d.last_worker_run && d.last_worker_run !== 'never' ? d.last_worker_run : 'never';
            const lt = d.last_trigger_get && d.last_trigger_get !== 'never' ? d.last_trigger_get : 'never';
            const cn = d.cron_next ? d.cron_next : 'OFF';
            document.getElementById('iq-info-line').textContent = 'Last Run: ' + lr + ' | Trigger GET: ' + lt + ' | Cron: ' + cn;

            const w = document.getElementById('s-worker');
            const svgBolt = '<svg width="18" height="18" viewBox="0 0 24 24" fill="#0a84ff"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10"/></svg>';
            const svgWait = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#ff9f0a" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>';
            const svgCheck = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#30d158" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>';
            if (d.slots_busy > 0) {
                w.innerHTML = svgBolt + ' ' + d.slots_busy + '/' + d.slots_total;
                w.style.color = '#0a84ff';
            } else if (d.pending > 0) {
                w.innerHTML = svgWait + ' 0/' + d.slots_total;
                w.style.color = '#ff9f0a';
            } else {
                w.innerHTML = svgCheck + ' 0/' + d.slots_total;
                w.style.color = '#30d158';
            }
        } catch(e) {}
    }

    function renderItems() {
        const el = document.getElementById('tbl');
        let items = allItems;
        if (activeFilter) items = items.filter(i => i.status === activeFilter);
        if (sellerFilter) items = items.filter(i => (i.seller || '') === sellerFilter);
        if (dateFilter) items = items.filter(i => (i.created_at || '').substring(0,10) === dateFilter);
        const totalFiltered = items.length;
        items = items.slice(0, displayLimit);
        if (!items || items.length === 0) {
            const reasons = [];
            if (activeFilter) reasons.push('status '+activeFilter);
            if (sellerFilter) reasons.push('seller '+sellerFilter);
            if (dateFilter) reasons.push('data '+dateFilter);
            el.innerHTML = '<tr><td colspan="12" style="text-align:center;color:#48484a;padding:40px;">'+(reasons.length ? 'Nenhum item com '+reasons.join(' + ') : 'Fila vazia')+'</td></tr>';
            return;
        }

        el.innerHTML = items.map(i => {
            const sc = statusColor[i.status] || '#86868b';

            // Preview: cdn_url se completed, senao seller_url
            const imgSrc = i.cdn_url || i.seller_url || '';
            const img = imgSrc
                ? '<img src="'+imgSrc+'" style="width:44px;height:44px;object-fit:contain;border-radius:6px;background:#1a1a1a;" onerror="this.style.display=\'none\'">'
                : '<div style="width:44px;height:44px;background:#2c2c2e;border-radius:6px;display:flex;align-items:center;justify-content:center;">'+svgImage+'</div>';

            // Produto
            const name = i.product_name ? i.product_name.substring(0, 40) : '';
            const pid = i.product_id ? '<a href="post.php?post='+i.product_id+'&action=edit" style="color:#0a84ff;text-decoration:none;">'+i.product_id+'</a>' : '';
            const aid = i.attachment_id ? 'att:' + i.attachment_id : '';
            const meta = [pid, aid].filter(Boolean).join(' \u00B7 ');
            const sellerTag = i.seller ? '<span style="display:inline-block;background:#1f2937;color:#a5b4fc;font-size:10px;font-weight:600;padding:2px 8px;border-radius:980px;margin-top:4px;letter-spacing:.2px;">'+escapeHtml(i.seller)+'</span>' : '';

            // URL truncada
            let shortUrl = '\u2014';
            if (i.seller_url) {
                try {
                    const u = new URL(i.seller_url);
                    shortUrl = u.hostname.replace('www.','').substring(0,20) + u.pathname.substring(u.pathname.lastIndexOf('/')).substring(0,25);
                } catch(e) { shortUrl = i.seller_url.substring(0,40); }
            }

            // Status + erro
            const errColor = i.status === 'passed' ? '#ff9500' : '#ff453a';
            const err = i.error ? '<div style="color:'+errColor+';font-size:11px;max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="'+i.error.replace(/"/g,'&quot;')+'">'+i.error.substring(0,60)+'</div>' : '';

            // Tamanho
            let sizeCell = '\u2014';
            if (i.original_size && i.optimized_size && parseInt(i.original_size) > 0) {
                const orig = parseInt(i.original_size);
                const opt = parseInt(i.optimized_size);
                const pct = orig > 0 ? Math.round((1 - opt/orig) * 100) : 0;
                const pctColor = pct > 80 ? '#30d158' : pct > 50 ? '#ff9f0a' : '#86868b';
                sizeCell = fmtBytes(orig) + ' \u2192 ' + fmtBytes(opt) + '<br><span style="color:'+pctColor+';font-weight:600;">-'+pct+'%</span>';
            } else if (i.status === 'processing') {
                sizeCell = svgWait || '<span style="color:#0a84ff;">\u23F3</span>';
            }

            // Formato
            let fmtCell = '\u2014';
            if (i.format) {
                const origExt = i.seller_url ? (i.seller_url.split('.').pop().split('?')[0] || '').toLowerCase().substring(0,4) : '?';
                if (i.format === 'webp' && origExt !== 'webp') {
                    fmtCell = '<span style="color:#86868b;">'+origExt+'</span> \u2192 <span style="color:#30d158;font-weight:600;">webp</span>';
                } else {
                    fmtCell = '<span style="color:#86868b;">'+i.format+'</span>';
                }
            }

            // Dims
            let dimsCell = '\u2014';
            if (i.original_dims && i.final_dims && i.original_dims !== i.final_dims) {
                dimsCell = '<span style="color:#86868b;">'+i.original_dims+'</span><br>\u2192 <span style="color:#30d158;">'+i.final_dims+'</span>';
            } else if (i.final_dims) {
                dimsCell = '<span style="color:#86868b;">'+i.final_dims+'</span>';
            } else if (i.original_dims) {
                dimsCell = '<span style="color:#86868b;">'+i.original_dims+'</span>';
            }

            // Duration
            let durCell = '\u2014';
            if (i.started_at && i.completed_at) {
                const ms = new Date(i.completed_at.replace(' ','T')) - new Date(i.started_at.replace(' ','T'));
                const sec = ms / 1000;
                const color = sec < 3 ? '#30d158' : sec < 10 ? '#ff9f0a' : '#ff453a';
                durCell = '<span style="color:'+color+';font-weight:600;">'+sec.toFixed(1)+'s</span>';
            } else if (i.status === 'processing' && i.started_at) {
                const ms = Date.now() - new Date(i.started_at.replace(' ','T')).getTime();
                durCell = '<span style="color:#0a84ff;font-weight:600;">'+svgTimer+' '+(ms/1000).toFixed(0)+'s</span>';
            }

            return '<tr style="border-bottom:1px solid #2d2d2d;">'
                + '<td style="padding:8px 10px;">'+img+'</td>'
                + '<td style="padding:8px 10px;min-width:180px;"><div style="font-weight:600;font-size:12px;color:#f5f5f7;line-height:1.3;">'+name+'</div><div style="color:#86868b;font-size:11px;">'+meta+'</div>'+sellerTag+'</td>'
                + '<td style="padding:8px 10px;max-width:200px;"><div style="color:#48484a;font-size:11px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="'+(i.seller_url||'').replace(/"/g,'&quot;')+'">'+shortUrl+'</div></td>'
                + '<td style="padding:8px 10px;"><span style="color:'+sc+';font-weight:600;">'+i.status+'</span>'+err+'</td>'
                + '<td style="padding:8px 10px;text-align:center;font-size:11px;">'+sizeCell+'</td>'
                + '<td style="padding:8px 10px;text-align:center;font-size:12px;">'+fmtCell+'</td>'
                + '<td style="padding:8px 10px;text-align:center;font-size:11px;">'+dimsCell+'</td>'
                + '<td style="padding:8px 10px;text-align:center;color:#86868b;">'+i.attempts+'</td>'
                + '<td style="padding:8px 10px;color:#48484a;font-size:11px;">'+fmtDateTime(i.created_at)+'</td>'
                + '<td style="padding:8px 10px;color:#48484a;font-size:11px;">'+fmtDateTime(i.completed_at)+'</td>'
                + '<td style="padding:8px 10px;text-align:center;font-size:12px;">'+durCell+'</td>'
                + '<td style="padding:8px 10px;text-align:center;"><div style="display:flex;align-items:center;justify-content:center;gap:6px;"><button onclick="reprocessItem('+i.id+')" title="Reprocessar esta imagem — volta para pending e executa novamente" style="background:transparent;border:1px solid #3a3a3c;color:#ff9f0a;padding:6px 8px;border-radius:8px;cursor:pointer;display:inline-flex;align-items:center;transition:all .2s;" onmouseover="this.style.background=\'#2d2d2d\';this.style.borderColor=\'#ff9f0a\'" onmouseout="this.style.background=\'transparent\';this.style.borderColor=\'#3a3a3c\'">'+svgReprocess+'</button><button onclick="openLogModal('+i.id+')" title="Ver log, assinatura do request e estado atual do attachment" style="background:transparent;border:1px solid #3a3a3c;color:#0a84ff;padding:6px 8px;border-radius:8px;cursor:pointer;display:inline-flex;align-items:center;transition:all .2s;" onmouseover="this.style.background=\'#2d2d2d\';this.style.borderColor=\'#0a84ff\'" onmouseout="this.style.background=\'transparent\';this.style.borderColor=\'#3a3a3c\'">'+svgLog+'</button></div></td>'
                + '</tr>';
        }).join('');

        // Mostrar contador se há mais itens do que o exibido
        if (totalFiltered > items.length) {
            el.innerHTML += '<tr><td colspan="12" style="text-align:center;color:#86868b;padding:10px;font-size:12px;">Mostrando '+items.length+' de '+totalFiltered+' itens</td></tr>';
        }
    }

    async function loadRecent() {
        if (todayMode) return; // Não recarregar no auto-refresh quando está em modo Today
        try {
            const j = await post('ojf_iq_recent');
            if (!j.success) return;
            allItems = j.data.items || [];
            refreshSellerOptions();
            renderItems();
            document.getElementById('last-refresh').textContent = new Date().toLocaleTimeString();
        } catch(e) {}
    }

    async function processNow() {
        document.getElementById('msg').textContent = 'Processando...';
        try {
            const j = await post('ojf_iq_process_now');
            if (j.success) {
                document.getElementById('msg').textContent = j.data.processed + ' processadas (' + j.data.pending_before + ' \u2192 ' + j.data.pending_after + ')';
            } else {
                document.getElementById('msg').textContent = 'Erro';
            }
        } catch(e) {
            document.getElementById('msg').textContent = 'Erro: ' + e.message;
        }
        loadStats(); loadRecent();
    }

    async function retryFailed() {
        const j = await post('ojf_iq_retry');
        if (j.success) {
            document.getElementById('msg').textContent = j.data.retried + ' re-enfileiradas';
        }
        loadStats(); loadRecent();
    }

    async function clearCompleted() {
        const j = await post('ojf_iq_clear');
        if (j.success) {
            document.getElementById('msg').textContent = j.data.cleared + ' removidas';
        }
        loadStats(); loadRecent();
    }

    async function reprocessItem(id) {
        if (!confirm('Reprocessar item #' + id + '?')) return;
        document.getElementById('msg').textContent = 'Reprocessando #' + id + '...';
        const j = await post('ojf_iq_reprocess', {item_id: id});
        if (j.success) {
            document.getElementById('msg').textContent = 'Item #' + id + ' re-enfileirado com sucesso';
        } else {
            document.getElementById('msg').textContent = 'Erro: ' + (j.data || 'falha ao reprocessar');
        }
        loadStats(); loadRecent();
    }

    async function clearFailed() {
        const j = await post('ojf_iq_clear_failed');
        if (j.success) {
            const parts = [];
            if (j.data.converted > 0) parts.push(j.data.converted + ' convertidos para passed');
            if (j.data.deleted > 0) parts.push(j.data.deleted + ' failed removidos');
            document.getElementById('msg').textContent = parts.join(', ') || 'Nenhum failed encontrado';
        }
        loadStats(); loadRecent();
    }

    // Init + auto-refresh
    loadStats();
    loadRecent();
    setInterval(loadStats, 5000);
    setInterval(loadRecent, 10000);
    document.getElementById('iq-log-modal').addEventListener('click', function(e) {
        if (e.target === this) closeLogModal();
    });
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') closeLogModal();
    });
    </script>
    <?php
}
